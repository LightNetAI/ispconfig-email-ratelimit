--[[
  email_ratelimit.lua - per-mailbox / per-domain / per-source rate limiting for
  ISPConfig 3.3+ with Rspamd.

  Loaded from /etc/rspamd/rspamd.local.lua, after the generated configuration
  (/etc/rspamd/email_ratelimit.conf.lua) has defined the global ERL_CONFIG
  table. This file is a *generated* artefact: the server plugin rewrites it from
  the database. Do not edit it on the server, edit the ISPConfig GUI instead.

  Direction semantics (matches the schaal-it behaviour):
    outgoing - messages sent BY an authenticated mailbox (SASL user)
    incoming - messages delivered TO a local recipient

  Explicit external rules ("Mail > Ratelimit") apply to the *remote* party:
    type=in   -> matched against the envelope sender
    type=out  -> matched against a destination recipient
    type=both -> either side

  Counters live in Redis under the "erl" prefix. Keys embed the configured
  interval and the current window number, so every window starts empty without
  a cron job; the TTL only cleans up stale keys. Every Redis/Lua call is
  pcall-wrapped: on any error we fail OPEN (mail is accepted), never blocking
  mail because a counter store is unavailable.
--]]

local rspamd_logger = require "rspamd_logger"
local lua_util = require "lua_util"
local lua_redis = require "lua_redis"

local N = "email_ratelimit"

-- Configuration is supplied by the generated
-- /etc/rspamd/email_ratelimit.conf.lua as a single global table.
local ERL = rawget(_G, 'ERL_CONFIG') or {}

local settings = {
  enabled  = false,
  out      = { enabled = false, limit = 0, interval = 3600 },
  in_      = { enabled = false, limit = 0, interval = 3600 },
  -- per-user overrides, filled from the generated user map
  users    = {},
  -- explicit external limits: key "address|direction" -> { limit, interval }
  extern   = {},
  symbol   = 'ERL_RATELIMIT',
  redis_params = nil,
  script_id    = nil,
}

--[[ ------------------------------------------------------------------ helpers ]]

local function lower(s)
  return string.lower(tostring(s or ''))
end

local function parse_interval(v)
  local iv = tonumber(tostring(v or ''))
  if not iv or iv <= 0 then iv = 3600 end
  return math.floor(iv)
end

local function parse_limit(v)
  local lv = tonumber(tostring(v or ''))
  if not lv or lv <= 0 then return 0 end
  return math.floor(lv)
end

-- Fixed-window counter: all messages in [win*interval, (win+1)*interval) share
-- one bucket, so the key changes by itself when the window rolls over.
local function window_id(now, interval)
  if interval <= 0 then interval = 3600 end
  return math.floor(now / interval)
end

local function bucket_key(kind, who, now, interval)
  if interval <= 0 then interval = 3600 end
  return string.format('erl:%d:%d:%s:%s', interval, window_id(now, interval), kind, who)
end

-- Keep the key a little longer than its window, so a message in flight at the
-- boundary cannot resurrect a stale counter.
local function bucket_ttl(interval)
  if interval <= 0 then interval = 3600 end
  return interval * 2
end

--[[ --------------------------------------------------------- limit resolution ]]

-- Resolve one direction for one mailbox.
--   no entry for this mailbox -> use the server default (if enabled)
--   entry with <dir>_on true  -> explicit: limited to <dir>_limit
--   entry with <dir>_on false -> explicit: unlimited for this direction
-- The field names use <dir>_limit / <dir>_interval because "in" is a Lua keyword.
-- Returns nil when the message must not be limited at all.
local function resolve_user(settings, address, direction, default, now)
  local u = settings.users[address]
  if u ~= nil then
    if u[direction .. '_on'] == false then
      return nil -- explicitly unlimited
    end
    local limit = tonumber(u[direction .. '_limit']) or 0
    if limit <= 0 then
      return nil
    end
    local interval = tonumber(u[direction .. '_interval']) or 0
    if interval <= 0 then
      interval = default.interval
    end
    return {
      limit    = limit,
      interval = interval,
      key      = bucket_key(direction, address, now, interval),
      scope    = 'mailbox:' .. address,
    }
  end

  -- No mailbox override: fall back to the server/domain default, but only when
  -- that default is actually enabled. A disabled default is *not* a reason to
  -- skip the per-mailbox overrides resolved above, which is why this check
  -- lives here and not at the call site.
  if not default or default.enabled ~= true or (tonumber(default.limit) or 0) <= 0 then
    return nil
  end
  local interval = tonumber(default.interval) or 3600
  return {
    limit    = tonumber(default.limit),
    interval = interval,
    key      = bucket_key(direction, address, now, interval),
    scope    = 'mailbox:' .. address,
  }
end

-- Exact address match, then domain match, then "both" rules.
local function resolve_extern(settings, address, direction, now)
  local addr = lower(address)
  if addr == '' then return nil end
  local domain = addr:match('@(.+)$')
  local cands = { addr }
  if domain then cands[#cands + 1] = '@' .. domain end

  for _, key in ipairs(cands) do
    for _, dir in ipairs({ direction, 'both' }) do
      local e = settings.extern[key .. '|' .. dir]
      if e and e.limit and e.limit > 0 then
        return {
          limit    = e.limit,
          interval = e.interval,
          key      = bucket_key('x' .. direction, key, now, e.interval),
          scope    = 'external:' .. key,
        }
      end
    end
  end
  return nil
end

--[[ ------------------------------------------------------------------ counters ]]

-- Atomic check-and-increment: increments only while the counter is below the
-- limit, so a rejected message never consumes quota. Returns (allowed, count).
-- KEYS[1] = bucket, ARGV[1] = limit, ARGV[2] = ttl in seconds.
local CHECK_AND_INCR = [[
local current = redis.call('GET', KEYS[1])
if current and tonumber(current) >= tonumber(ARGV[1]) then
  return {0, tonumber(current)}
end
local c = redis.call('INCR', KEYS[1])
if c == 1 then
  redis.call('EXPIRE', KEYS[1], tonumber(ARGV[2]))
end
return {1, c}
]]

local function check_limit(task, res)
  local function cb(err, data)
    if err then
      -- fail open
      rspamd_logger.errx(task, '%s: redis error for %s: %s - allowing mail',
          N, res.key, err)
      return
    end
    if type(data) ~= 'table' then
      rspamd_logger.errx(task, '%s: empty redis reply for %s - allowing mail',
          N, res.key)
      return
    end
    local allowed = tonumber(data[1]) or 0
    local count = tonumber(data[2]) or 0
    if allowed == 1 then
      rspamd_logger.debugm(N, task, 'erl ok %s (%s/%s)',
          res.key, count, res.limit)
    else
      local msg = string.format(
        'Rate limit exceeded: not more than %d message(s) per %d second(s) allowed (%s)',
        res.limit, res.interval, res.scope)
      task:set_pre_result('soft reject', msg, N)
      rspamd_logger.infox(task, 'erl DEFER %s (%s/%s): %s',
          res.key, count, res.limit, res.scope)
    end
  end

  local ok, err = pcall(function()
    -- KEYS and ARGV are separate positional arguments:
    --   exec_redis_script(id, params, callback, keys, args)
    lua_redis.exec_redis_script(settings.script_id,
        { key = res.key, task = task, is_write = true },
        cb,
        { res.key },
        { tostring(res.limit), tostring(bucket_ttl(res.interval)) })
  end)
  if not ok then
    rspamd_logger.errx(task, '%s: exec failed for %s: %s - allowing mail',
        N, res.key, err)
  end
end

--[[ ------------------------------------------------------------------- prefilter ]]

-- Recipients (SMTP level), lowercased, deduplicated.
local function recipients(task)
  local rcpts = task:get_recipients('smtp')
  local out = {}
  local seen = {}
  if type(rcpts) == 'table' then
    for _, r in ipairs(rcpts) do
      local a = r.addr
      if a and a ~= '' then
        a = lower(a)
        if not seen[a] then
          seen[a] = true
          out[#out + 1] = a
        end
      end
    end
  end
  return out
end

-- Envelope sender, tolerating both shapes returned by task:get_from().
local function sender_address(task)
  local from = task:get_from('smtp')
  if type(from) ~= 'table' then
    from = task:get_from('mime')
  end
  if type(from) ~= 'table' then return nil end
  local a = from.addr
  if not a and type(from[1]) == 'table' then a = from[1].addr end
  if not a or a == '' then return nil end
  return lower(a)
end

local function ratelimit_cb(task)
  if not settings.enabled then return false end
  if not settings.redis_params or not settings.script_id then
    rspamd_logger.debugm(N, task, 'no redis script configured - skipping')
    return false
  end

  local now = os.time()
  local checks = {}

  -- outgoing: the authenticated mailbox, plus every destination matched by an
  -- external rule (one counter per distinct external source, not per recipient)
  local user = task:get_user()
  if user and user ~= '' then
    user = lower(user)
    local res = resolve_user(settings, user, 'out', settings.out, now)
    if res then checks[#checks + 1] = res end

    local seen = {}
    for _, rcpt in ipairs(recipients(task)) do
      local x = resolve_extern(settings, rcpt, 'out', now)
      if x and not seen[x.scope] then
        seen[x.scope] = true
        checks[#checks + 1] = x
      end
    end
  end

  -- incoming: the remote sender (external rules) and every local recipient
  local sender = sender_address(task)
  if sender then
    local x = resolve_extern(settings, sender, 'in', now)
    if x then checks[#checks + 1] = x end
  end

  for _, rcpt in ipairs(recipients(task)) do
    local res = resolve_user(settings, rcpt, 'in', settings.in_, now)
    if res then checks[#checks + 1] = res end
  end

  for _, res in ipairs(checks) do
    check_limit(task, res)
  end

  return false
end

--[[ ----------------------------------------------------------------- registration ]]

local function any_user(dir)
  for _, u in pairs(settings.users) do
    if u[dir .. '_on'] == true then return true end
  end
  return false
end

local function any_extern(dir)
  for k, e in pairs(settings.extern) do
    if e.limit and e.limit > 0 then
      if k:match('|' .. dir .. '$') or k:match('|both$') then
        return true
      end
    end
  end
  return false
end

local function load_config()
  local opts = ERL
  if not opts or not opts.enabled then
    rspamd_logger.infox(rspamd_config, '%s: not enabled or no configuration, module idle', N)
    return
  end

  settings.enabled = true

  local d = opts.defaults or {}
  settings.out.enabled = (d.out and d.out.enabled) and true or false
  settings.out.limit = parse_limit(d.out and d.out.limit)
  settings.out.interval = parse_interval(d.out and d.out.interval or 3600)

  settings.in_.enabled = (d.in_ and d.in_.enabled) and true or false
  settings.in_.limit = parse_limit(d.in_ and d.in_.limit)
  settings.in_.interval = parse_interval(d.in_ and d.in_.interval or 3600)

  -- per-user overrides
  local users = opts.users or {}
  for addr, u in pairs(users) do
    settings.users[lower(addr)] = u
  end

  -- explicit external limits
  local ext = opts.extern or {}
  for _, e in ipairs(ext) do
    if e.source and e.type then
      local key = lower(e.source) .. '|' .. tostring(e.type)
      settings.extern[key] = {
        limit    = parse_limit(e.limit),
        interval = parse_interval(e.interval),
      }
    end
  end

  -- A direction is active when the server default is on OR when any mailbox /
  -- external rule overrides it. Without this, "unlimited by default, cap a few
  -- mailboxes" would silently enforce nothing.
  local active_out = settings.out.enabled or any_user('out') or any_extern('out')
  local active_in  = settings.in_.enabled or any_user('in') or any_extern('in')

  if not active_out and not active_in then
    rspamd_logger.infox(rspamd_config,
        '%s: no direction enabled and no overrides, module idle', N)
    settings.enabled = false
    return
  end

  settings.redis_params = lua_redis.parse_redis_server(N)
  if not settings.redis_params then
    rspamd_logger.errx(rspamd_config,
        '%s: no redis servers configured - rate limiting will fail open', N)
  else
    -- Load the script once, at configuration time (never per message).
    local ok, id = pcall(lua_redis.add_redis_script, CHECK_AND_INCR, settings.redis_params, 3)
    if ok and id then
      settings.script_id = id
    else
      rspamd_logger.errx(rspamd_config,
          '%s: cannot register redis script, rate limiting will fail open: %s',
          N, tostring(id))
    end
  end

  local id = rspamd_config:register_symbol({
    name     = 'ERL_CHECK',
    type     = 'prefilter',
    callback = ratelimit_cb,
    priority = lua_util.symbols_priorities.medium,
    flags    = 'empty,nostat',
  })
  rspamd_config:register_symbol({
    name   = settings.symbol,
    type   = 'virtual',
    parent = id,
    score  = 0.0,
  })

  local nusers, next_ = 0, 0
  for _ in pairs(settings.users) do nusers = nusers + 1 end
  for _ in pairs(settings.extern) do next_ = next_ + 1 end

  rspamd_logger.infox(rspamd_config,
      '%s: active (out=%s/%s interval=%s, in=%s/%s interval=%s, %d mailbox override(s), %d external limit(s))',
      N,
      tostring(settings.out.enabled), tostring(settings.out.limit), tostring(settings.out.interval),
      tostring(settings.in_.enabled), tostring(settings.in_.limit), tostring(settings.in_.interval),
      nusers, next_)
end

load_config()
