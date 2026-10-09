import { spawnSync } from 'node:child_process';

export default function setup() {
  const result = spawnSync('php', ['tests/e2e/fixture.php'], {
    encoding: 'utf8', env: { ...process.env, APP_ENV: 'testing', TENANT_DOMAIN: 'localhost' },
  });
  if (result.status !== 0) throw new Error(`Browser fixture setup failed: ${result.stderr || result.stdout}`);
}
