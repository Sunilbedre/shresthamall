const { Client } = require('./_ssh_tmp/node_modules/ssh2');
const path = require('path');
const fs = require('fs');

const LOCAL = path.join(__dirname, '..');
const REMOTE = '/home/u432886921/domains/shreeshtafamily.store/public_html';
const PASS = process.env.SFS_SSH_PASS || '';
if (!PASS) {
  console.error('Set SFS_SSH_PASS env var, then re-run.');
  process.exit(1);
}

const FILES = [
  'app/OfferCatalog.php',
  'app/Products.php',
  'app/CustomerService.php',
  'app/VoucherService.php',
  'app/VoucherPresenter.php',
  'app/WhatsAppService.php',
  'app/Database.php',
  'app/bootstrap.php',
  'public/offer.php',
  'public/verify.php',
  'public/check_mobile.php',
  'admin/offers.php',
  'admin/events.php',
  'admin/exports.php',
  'admin/registrations.php',
  'admin/settings.php',
  'templates/admin_nav.php',
  'router.php',
  'scripts/seed_offers.php',
  'scripts/verify_customers_count.php',
  'sfs-ops-m9k2x7q4/offers.php',
  'sfs-ops-m9k2x7q4/events.php',
  'sfs-ops-m9k2x7q4/Events.php',
];

fs.writeFileSync(
  path.join(LOCAL, 'scripts/verify_customers_count.php'),
  `<?php
require __DIR__ . '/../app/bootstrap.php';
$pdo = Database::connection();
echo 'CUSTOMERS=' . (int)$pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn() . PHP_EOL;
echo 'ACTIVE_DATES=' . implode(',', OfferCatalog::eventDates(true)) . PHP_EOL;
echo 'PRODUCTS=' . count(OfferCatalog::products(false)) . PHP_EOL;
echo 'SLOTS=' . count(OfferCatalog::allSlotsDetailed()) . PHP_EOL;
`
);

const conn = new Client();
conn
  .on('ready', () => {
    console.log('SSH connected');
    conn.sftp((err, sftp) => {
      if (err) throw err;
      const put = (l, r) =>
        new Promise((res, rej) => sftp.fastPut(l, r, (e) => (e ? rej(e) : res())));
      const mkdirp = (dir) => new Promise((res) => sftp.mkdir(dir, () => res()));

      (async () => {
        for (const f of FILES) {
          const local = path.join(LOCAL, f);
          if (!fs.existsSync(local)) {
            console.warn('skip missing', f);
            continue;
          }
          const remote = REMOTE + '/' + f.replace(/\\/g, '/');
          await mkdirp(remote.substring(0, remote.lastIndexOf('/')));
          await put(local, remote);
          console.log('up', f);
        }

        const cmd = [
          `cd ${REMOTE}`,
          'php scripts/verify_customers_count.php',
          'php scripts/seed_offers.php --force',
          'php scripts/verify_customers_count.php',
        ].join(' && ');

        conn.exec(cmd, (e2, stream) => {
          if (e2) throw e2;
          let o = '';
          stream.on('data', (d) => (o += d));
          stream.stderr.on('data', (d) => (o += d));
          stream.on('close', (code) => {
            console.log(o);
            console.log('exit', code);
            conn.end();
          });
        });
      })().catch((e) => {
        console.error(e);
        process.exit(1);
      });
    });
  })
  .on('error', (e) => {
    console.error('SSH', e.message);
    process.exit(1);
  })
  .connect({
    host: '217.21.85.101',
    port: 65002,
    username: 'u432886921',
    password: PASS,
    readyTimeout: 60000,
  });
