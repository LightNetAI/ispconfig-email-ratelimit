--[[
  email_ratelimit.lua - per-mailbox / per-domain / per-source rate limiting for
  ISPConfig 3.3+ with Rspamd.

  Loaded from /etc/rspamd/rspamd.local.lua (Rspamd's official customisation
  entry point, dofile()d by rules/rspamd.lua). This file is a *generated*
  artefact: the server plugin rewrites it from the database. Do not edit it on
  the server, edit the ISPConfig GUI instead.

  Direction semantics (matches the schaal-it behaviour):
    outgoing - messages sent BY an authenticated mailbox (SASL user)
    incoming - messages delivered TO a mailbox (recipient based)

  Counters live in Redis under the "erl" prefix, keyed by day so they reset at
  midnight without a cron job. Every Redis/Lua call is pcall-wrapped: on any
  error we fail OPEN (mail is accepted), never blocking mail because a counter
  store is unavailable.
--]]

local rspamd_logger = require "rspamd_logger"
local lua_util = require "lua_util"
local lua_redis = require "lua_redis"

local N = "email_ratelimit"

--[[ ------------------------------------------------------------------ config ]]

-- Configuration is supplied by the generated /etc/rspamd/rspamd.local.lua as a
-- single global table. Reading it from a dofile()d Lua file (rather than from a
-- UCL module section) keeps the generated artefact trivially robust: the file
-- either parses as Lua or it does not, with no include-order or merge semantics
-- to get wrong.
local ERL = rawget(_G, 'ERL_CONFIG') or {}

local settings = {
  enabled  = false,
  interval = 3600,
  out      = { enabled = false, limit = 0 },
  in_      = { enabled = false, limit = 0 },
  -- per-user overrides, filled from the generated user map
  users    = {},
  -- explicit external limits: key "address|direction" -> { limit, interval }
  extern   = {},
  expire   = 0,
  symbol   = 'ERL_RATELIMIT',
  redis_params = nil,
}

local function parse_interval(v)
  local iv = tonumber(tostring(v or ''))
  if not iv or iv <= 0 then iv = 3600 end
  return iv
end

local function parse_limit(v)
  local lv = tonumber(tostring(v or ''))
  if not lv or lv <= 0 then return 0 end
  return lv
end

--[[ -------------------------------------------------------------- redis keying ]]

-- Bucket key. Day-scoped so counters reset automatically at midnight; the TTL
-- only cleans up the previous day's keys.
local function bucket_key(kind, who, now)
  local day = os.date('!%Y-%m-%d', now)
  return string.format('erl:%s:%s:%s', day, kind, who)
end

local function day_ttl(now)
  local seconds_left = 86400 - (now % 86400)
  -- keep the key for the rest of today plus a margin
  return seconds_left + 3600
end

--[[ --------------------------------------------------------- limit resolution ]]

-- Resolve one direction for one mailbox.
--   no entry for this mailbox -> use the server default
--   entry with <dir>_on true  -> explicit: limited to <dir>_limit
--   entry with <dir>_on false -> explicit: unlimited for this direction
-- The field names use <dir>_limit / <dir>_interval because "in" is a Lua keyword.
-- Returns nil when the message must not be limited at all.
local function resolve_user(settings, address, direction, default, now)
  local u = settings.users[address]
  if u == nil then
    return default
  end
  if u[direction .. '_on'] == false then
    return nil -- explicitly unlimited
  end
  local limit = tonumber(u[direction .. '_limit']) or 0
  if limit <= 0 then
    return nil
  end
  local interval = tonumber(u[direction .. '_interval']) or default.interval
  if interval <= 0 then interval = default.interval end
  return {
    enabled  = true,
    limit    = limit,
    interval = interval,
    key      = bucket_key(direction, address, now),
    scope    = 'mailbox:' .. address,
  }
end

-- Exact address match, then domain match, then the mailbox/global default.
local function resolve_extern(settings, address, direction, now)
  local addr = string.lower(tostring(address or ''))
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
          key      = bucket_key('x' .. direction, key, now),
          scope    = 'external:' .. key,
        }
      end
    end
  end
  return nil
end

--[[ ------------------------------------------------------------------ counters ]]

-- Atomic check-and-increment: increments only while the counter is below the
-- limit, so a rejected burst never consumes quota. Returns (allowed, count).
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

local function check_limit(task, redis_params, res)
  local incr = lua_redis.add_redis_script(CHECK_AND_INCR, redis_params, 3)

  local function cb(err, data)
    if err then
      -- fail open
      rspamd_logger.errx(task, '%s: redis error for %s: %s - allowing mail',
          N, res.key, err)
      return
    end
    local allowed = tonumber(data[1])
    local count = tonumber(data[2])
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
    lua_redis.exec_redis_script(incr,
        { key = res.key, task = task, is_write = true },
        cb,
        { res.key, tostring(res.limit), tostring(day_ttl(os.time())) })
  end)
  if not ok then
    rspamd_logger.errx(task, '%s: exec failed for %s: %s', N, res.key, err)
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
        a = string.lower(a)
        if not seen[a] then
          seen[a] = true
          out[#out + 1] = a
        end
      end
    end
  end
  return out
end

local function ratelimit_cb(task)
  if not settings.enabled then return false end

  if not settings.redis_params then
    rspamd_logger.debugm(N, task, 'no redis configured - skipping')
    return false
  end

  local now = os.time()
  local checks = {}

  -- outgoing: authenticated mailbox
  local user = task:get_user()
  if user and user ~= '' and settings.out.enabled and settings.out.limit > 0 then
    user = string.lower(user)
    local res = resolve_extern(settings, user, 'out', now)
    if not res then
      res = resolve_user(settings, user, 'out', {
        enabled  = true,
        limit    = settings.out.limit,
        interval = settings.out.interval,
        key      = bucket_key('out', user, now),
        scope    = 'mailbox:' .. user,
      }, now)
    end
    if res then checks[#checks + 1] = res end
  end

  -- incoming: each local recipient
  if settings.in_.enabled and settings.in_.limit > 0 then
    for _, rcpt in ipairs(recipients(task)) do
      local res = resolve_extern(settings, rcpt, 'in', now)
      if not res then
        res = resolve_user(settings, rcpt, 'in', {
          enabled  = true,
          limit    = settings.in_.limit,
          interval = settings.in_.interval,
          key      = bucket_key('in', rcpt, now),
          scope    = 'mailbox:' .. rcpt,
        }, now)
      end
      if res then checks[#checks + 1] = res end
    end
  end

  for _, res in ipairs(checks) do
    check_limit(task, settings.redis_params, res)
  end

  return false
end

--[[ ----------------------------------------------------------------- registration ]]

local function load_config()
  local opts = ERL
  if not opts or not opts.enabled then
    rspamd_logger.infox(rspamd_config, '%s: not enabled or no configuration, module idle', N)
    return
  end

  settings.enabled = true
  settings.interval = parse_interval(opts.interval)

  local d = opts.defaults or {}
  settings.out.enabled = (d.out and d.out.enabled) and true or false
  settings.out.limit = parse_limit(d.out and d.out.limit)
  settings.out.interval = parse_interval(d.out and d.out.interval or settings.interval)

  settings.in_.enabled = (d.in_ and d.in_.enabled) and true or false
  settings.in_.limit = parse_limit(d.in_ and d.in_.limit)
  settings.in_.interval = parse_interval(d.in_ and d.in_.interval or settings.interval)

  settings.expire = settings.interval * 2
  if settings.expire < 86400 then settings.expire = 86400 end

  -- per-user overrides
  local users = opts.users or {}
  for addr, u in pairs(users) do
    settings.users[string.lower(addr)] = u
  end

  -- explicit external limits
  local ext = opts.extern or {}
  for _, e in ipairs(ext) do
    if e.source and e.type then
      local key = string.lower(e.source) .. '|' .. e.type
      settings.extern[key] = {
        limit    = parse_limit(e.limit),
        interval = parse_interval(e.interval),
      }
    end
  end

  settings.redis_params = lua_redis.parse_redis_server(N)
  if not settings.redis_params then
    rspamd_logger.errx(rspamd_config,
        '%s: no redis servers configured - rate limiting will fail open', N)
  end

  if not settings.out.enabled and not settings.in_.enabled then
    rspamd_logger.infox(rspamd_config,
        '%s: no direction enabled (outgoing and incoming both off), module idle', N)
    settings.enabled = false
    return
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
