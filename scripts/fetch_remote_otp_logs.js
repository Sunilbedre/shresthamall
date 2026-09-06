/**
 * Pull production OTP / PHP / webhook logs over SSH.
 * Usage: SFS_SSH_PASS='...' node scripts/fetch_remote_otp_logs.js [mobile_digits]
 */
const { Client } = require('./_ssh_tmp/node_modules/ssh2');

const PASS = process.env.SFS_SSH_PASS || process.env.SFS_SSH_PASSWORD || '';
const NEEDLE = (process.argv[2] || '8867476915').replace(/\D+/g, '');
const REMOTE = '/home/u432886921/domains/shreeshtafamily.store/public_html';

if (!PASS) {
  console.error('Set SFS_SSH_PASS env var, then re-run.');
  process.exit(1);
}

const last4 = NEEDLE.slice(-4);
const cmd = [
  "echo '=== HOST / LOG DIR ==='",
  'hostname',
  'ls -la ' + REMOTE + '/storage/logs 2>/dev/null || ls -la ' + REMOTE + '/storage 2>/dev/null || echo no_storage',
  "echo '=== OTP LOG ==='",
  'if [ -f ' + REMOTE + '/storage/logs/otp.log ]; then grep -F \'' + last4 + '\' ' + REMOTE + '/storage/logs/otp.log | tail -n 50 || tail -n 50 ' + REMOTE + '/storage/logs/otp.log; else echo otp.log_missing; fi',
  "echo '=== PHP ERROR ==='",
  'tail -n 60 ' + REMOTE + '/storage/logs/php-error.log 2>/dev/null || echo no_php_error_log',
  "echo '=== WEBHOOK ==='",
  'if [ -f ' + REMOTE + '/storage/logs/webhook.log ]; then grep -E \'8867476915|918867476915|statuses|failed|delivered|undelivered\' ' + REMOTE + '/storage/logs/webhook.log | tail -n 40 || tail -n 30 ' + REMOTE + '/storage/logs/webhook.log; else echo no_webhook_log; fi',
  "echo '=== SMSALERT ENV KEYS ==='",
  'grep -E \'^SMSALERT_\' ' + REMOTE + '/.env | sed \'s/=.*/=***/\' || echo no_smsalert_env',
].join('\n');

const conn = new Client();
conn
  .on('ready', () => {
    conn.exec(cmd, (err, stream) => {
      if (err) throw err;
      let out = '';
      stream
        .on('close', (code) => {
          console.log(out);
          console.log('exit', code);
          conn.end();
        })
        .on('data', (d) => { out += d.toString(); });
      stream.stderr.on('data', (d) => { out += d.toString(); });
    });
  })
  .on('error', (e) => {
    console.error('SSH error:', e.message);
    process.exit(1);
  })
  .connect({
    host: '217.21.85.101',
    port: 65002,
    username: 'u432886921',
    password: PASS,
    readyTimeout: 20000,
  });
