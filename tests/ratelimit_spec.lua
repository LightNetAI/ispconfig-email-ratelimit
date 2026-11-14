--[[
  Behavioural tests for server/lua/email_ratelimit.lua.

  Runs under Lua 5.1 / LuaJIT (what Rspamd embeds). Each case runs in its own
  process because the module registers itself at load time:

    lua5.1 tests/ratelimit_spec.lua <case>

  Exits non-zero on failure.
--]]

local case = arg[1] or ''
local module_path = arg[2] or (os.getenv('ERL_MODULE') or 'server/lua/email_ratelimit.lua')

local failures = 0
local function ok(name, cond, detail)
  if cond then
    io.write('  ok   ' .. name .. '\n')
  else
    failures = failures + 1
    io.write('  FAIL ' .. name .. (detail and ('  [' .. tostring(detail) .. ']') or '') .. '\n')
  end
end

-- ---------------------------------------------------------------- rspamd stubs
local registered = {}
local execs = {}
local defer_msg = nil
local logged = {}

package.preload['rspamd_logger'] = function()
  return {
    infox  = function(...) logged[#logged + 1] = {'info', ...} end,
    errx   = function(...) logged[#logged + 1] = {'err', ...} end,
    debugm = function(...) logged[#logged + 1] = {'debug', ...} end,
  }
end

package.preload['lua_util'] = function()
  return { symbols_priorities = { medium = 0 } }
end

package.preload['lua_redis'] = function()
  return {
    parse_redis_server = function() return { servers = { '127.0.0.1' } } end,
    add_redis_script = function(script, params, level)
      assert(type(script) == 'string' and #script > 0, 'script must be a string')
      assert(type(params) == 'table', 'redis params must be a table')
      return 42
    end,
    exec_redis_script = function(id, params, cb, keys, args)
      execs[#execs + 1] = { id = id, params = params, keys = keys, args = args }
      cb(nil, REPLY)
      return true
    end,
  }
end

REPLY = { 1, 1 }

rspamd_config = {
  register_symbol = function(self, def)
    registered[#registered + 1] = def
    return #registered
  end,
}

-- ------------------------------------------------------------------- fixtures
local function make_task(opts)
  opts = opts or {}
  local rcpts = {}
  for _, a in ipairs(opts.rcpts or {}) do rcpts[#rcpts + 1] = { addr = a } end
  return {
    get_user = function() return opts.user end,
    get_recipients = function() return rcpts end,
    get_from = function() return opts.from and { addr = opts.from } or nil end,
    set_pre_result = function(self, kind, msg, mod)
      defer_msg = { kind = kind, msg = msg, mod = mod }
    end,
  }
end

local function run(task)
  local sym = registered[1]
  if not sym then return end
  sym.callback(task)
end

local function one_exec()
  return execs[1]
end

-- --------------------------------------------------------------------- cases
if case == 'global_out' then
  ERL_CONFIG = {
    enabled = true,
    defaults = { out = { enabled = true, limit = 500, interval = 3600 },
                 in_ = { enabled = false, limit = 0, interval = 3600 } },
    users = {}, extern = {},
  }
  dofile(module_path)
  run(make_task({ user = 'alice@example.com', rcpts = { 'bob@example.com' }, from = 'alice@example.com' }))

  ok('one check for an authenticated sender', #execs == 1, #execs)
  local e = one_exec() or {}
  ok('script id used', e.id == 42, tostring(e.id))
  ok('single KEYS entry', type(e.keys) == 'table' and #e.keys == 1, e.keys and #e.keys)
  ok('key is the bucket', e.keys and e.keys[1] == e.params.key)
  ok('ARGV carries limit and ttl', e.args and e.args[1] == '500' and e.args[2] == '7200',
     e.args and table.concat(e.args, ','))
  ok('bucket is window + direction + mailbox', e.keys and e.keys[1]:match('^erl:3600:%d+:out:alice@example%.com$') ~= nil,
     e.keys and e.keys[1])

elseif case == 'override_only' then
  -- regression: per-mailbox limit with the server default switched off
  ERL_CONFIG = {
    enabled = true,
    defaults = { out = { enabled = false, limit = 0, interval = 3600 },
                 in_ = { enabled = false, limit = 0, interval = 3600 } },
    users = { ['alice@example.com'] = { out_on = true, out_limit = 10, out_interval = 60,
                                       in_on = false, in_limit = 0, in_interval = 3600 } },
    extern = {},
  }
  dofile(module_path)
  run(make_task({ user = 'alice@example.com', rcpts = { 'bob@example.com' }, from = 'alice@example.com' }))

  ok('override enforced while global is off', #execs == 1, #execs)
  local e = one_exec() or {}
  ok('override limit wins', e.args and e.args[1] == '10', e.args and e.args[1])
  ok('override interval drives ttl', e.args and e.args[2] == '120', e.args and e.args[2])
  ok('window uses the override interval', e.keys and e.keys[1]:match('^erl:60:%d+:out:') ~= nil,
     e.keys and e.keys[1])

elseif case == 'override_in' then
  ERL_CONFIG = {
    enabled = true,
    defaults = { out = { enabled = false, limit = 0, interval = 3600 },
                 in_ = { enabled = false, limit = 0, interval = 3600 } },
    users = { ['alice@example.com'] = { out_on = false, out_limit = 0, out_interval = 3600,
                                       in_on = true, in_limit = 5, in_interval = 3600 } },
    extern = {},
  }
  dofile(module_path)
  run(make_task({ user = nil, rcpts = { 'alice@example.com' }, from = 'x@remote.test' }))

  ok('incoming override enforced', #execs == 1, #execs)
  local e = one_exec() or {}
  ok('incoming bucket', e.keys and e.keys[1]:match('^erl:3600:%d+:in:alice@example%.com$') ~= nil,
     e.keys and e.keys[1])

elseif case == 'extern_out' then
  ERL_CONFIG = {
    enabled = true,
    defaults = { out = { enabled = false, limit = 0, interval = 3600 },
                 in_ = { enabled = false, limit = 0, interval = 3600 } },
    users = {},
    extern = { { source = '@gmail.com', type = 'out', limit = 5, interval = 3600 } },
  }
  dofile(module_path)
  run(make_task({ user = 'alice@example.com', rcpts = { 'bob@gmail.com' }, from = 'alice@example.com' }))

  ok('destination rule enforced', #execs == 1, #execs)
  local e = one_exec() or {}
  ok('matched on the recipient, not the sender', e.keys and e.keys[1]:match('^erl:3600:%d+:xout:@gmail%.com$') ~= nil,
     e.keys and e.keys[1])

elseif case == 'extern_out_dedupe' then
  ERL_CONFIG = {
    enabled = true,
    defaults = { out = { enabled = false, limit = 0, interval = 3600 },
                 in_ = { enabled = false, limit = 0, interval = 3600 } },
    users = {},
    extern = { { source = '@gmail.com', type = 'out', limit = 5, interval = 3600 } },
  }
  dofile(module_path)
  run(make_task({ user = 'alice@example.com',
                  rcpts = { 'a@gmail.com', 'b@gmail.com', 'c@gmail.com' },
                  from = 'alice@example.com' }))
  ok('same external rule counted once', #execs == 1, #execs)

elseif case == 'extern_in' then
  ERL_CONFIG = {
    enabled = true,
    defaults = { out = { enabled = false, limit = 0, interval = 3600 },
                 in_ = { enabled = false, limit = 0, interval = 3600 } },
    users = {},
    extern = { { source = '@bad.test', type = 'in', limit = 3, interval = 3600 } },
  }
  dofile(module_path)
  run(make_task({ user = nil, rcpts = { 'alice@example.com' }, from = 'spam@bad.test' }))

  ok('sender rule enforced', #execs == 1, #execs)
  local e = one_exec() or {}
  ok('matched on the envelope sender', e.keys and e.keys[1]:match('^erl:3600:%d+:xin:@bad%.test$') ~= nil,
     e.keys and e.keys[1])

elseif case == 'soft_reject' then
  REPLY = { 0, 99 }
  ERL_CONFIG = {
    enabled = true,
    defaults = { out = { enabled = true, limit = 500, interval = 3600 },
                 in_ = { enabled = false, limit = 0, interval = 3600 } },
    users = {}, extern = {},
  }
  dofile(module_path)
  run(make_task({ user = 'alice@example.com', rcpts = { 'bob@example.com' }, from = 'alice@example.com' }))

  ok('pre-result set to soft reject', defer_msg and defer_msg.kind == 'soft reject', defer_msg and defer_msg.kind)
  ok('message names the scope', defer_msg and defer_msg.msg:match('mailbox:alice@example%.com') ~= nil,
     defer_msg and defer_msg.msg)
  ok('message names the limit', defer_msg and defer_msg.msg:find('500', 1, true) ~= nil,
     defer_msg and defer_msg.msg)

elseif case == 'no_check_when_unlimited' then
  ERL_CONFIG = {
    enabled = true,
    defaults = { out = { enabled = false, limit = 0, interval = 3600 },
                 in_ = { enabled = false, limit = 0, interval = 3600 } },
    users = {}, extern = {},
  }
  dofile(module_path)
  ok('module goes idle without limits', registered[1] == nil)
  run(make_task({ user = 'alice@example.com', rcpts = { 'bob@example.com' } }))
  ok('no redis calls when idle', #execs == 0, #execs)

elseif case == 'disabled' then
  ERL_CONFIG = { enabled = false, defaults = {}, users = {}, extern = {} }
  dofile(module_path)
  ok('disabled module registers nothing', registered[1] == nil)
  run(make_task({ user = 'alice@example.com', rcpts = { 'bob@example.com' } }))
  ok('disabled module never calls redis', #execs == 0, #execs)

else
  io.write('unknown case: ' .. case .. '\n')
  os.exit(2)
end

if failures > 0 then
  io.write(('  -> %d failure(s)\n'):format(failures))
  os.exit(1)
end
