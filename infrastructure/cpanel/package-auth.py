"""Package the reviewed D109 PHP slice only; never include environment or data."""
from pathlib import Path
import hashlib
import json
import sys
import zipfile

root = Path(__file__).resolve().parents[2]
paths = '''app/Models/User.php
app/Models/AdminUser.php
app/Models/EmailCredentialToken.php
app/Services/StaffAuthService.php
app/Services/EmailAuthService.php
app/Services/StaffManagementService.php
app/Services/SupervisorApprovalService.php
app/Services/TillService.php
app/Services/SaleService.php
app/Services/StockService.php
app/Services/OfflineSaleService.php
app/Services/FinancialActorService.php
app/Services/CashDropService.php
app/Filament/Services/PlatformService.php
app/Http/Controllers/Api/V1/EmailAuthController.php
app/Http/Controllers/Api/V1/CashDropController.php
app/Http/Middleware/RequireStaffSession.php
app/Http/Middleware/RequireEnterpriseToken.php
app/Http/Middleware/NoStoreAuth.php
app/Http/Middleware/AdminSessionSecurity.php
app/Http/Resources/ManagedStaffResource.php
app/Http/Requests/Auth/EmailAuthRequest.php
app/Http/Requests/Auth/SupervisorCheckRequest.php
app/Http/Requests/Staff/SaveStaffRequest.php
app/Providers/AppServiceProvider.php
app/Providers/Filament/AdminPanelProvider.php
app/Filament/Auth/AdminLogin.php
app/Filament/Auth/AdminProfile.php
app/Filament/Auth/RequestAdminPasswordReset.php
app/Filament/Auth/ResetAdminPassword.php
app/Filament/Auth/AdminPasswordResetNotification.php
app/Rules/AdminPasswordLength.php
app/Support/PasswordResetMail.php
app/Console/Commands/CreatePlatformAdmin.php
app/Console/Commands/IssueEmailActivation.php
config/auth.php
config/mail.php
routes/api.php
database/migrations/2026_10_16_000042_add_email_credentials.php
database/migrations/2026_10_16_000043_create_admin_password_reset_tokens.php'''.splitlines()
output = (root / (sys.argv[1] if len(sys.argv) > 1 else 'tmp/BiziSmart-email-auth-backend-20261016.zip')).resolve()
if root / 'tmp' not in output.parents or output.suffix != '.zip':
    raise SystemExit('The archive must be a ZIP in workspace tmp.')
manifest = {}
with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_DEFLATED) as archive:
    for relative in paths:
        path = root / 'backend' / relative
        if path.is_symlink() or not path.is_file() or root / 'backend' not in path.resolve().parents:
            raise SystemExit('Invalid source path: ' + relative)
        data = path.read_bytes()
        if b'-----BEGIN PRIVATE KEY-----' in data or b'-----BEGIN RSA PRIVATE KEY-----' in data:
            raise SystemExit('Unexpected secret material in source')
        manifest[relative] = hashlib.sha256(data).hexdigest()
        archive.writestr(relative, data)
    archive.writestr('manifest.json', json.dumps(manifest, indent=2) + '\n')
with zipfile.ZipFile(output) as archive:
    for relative, digest in manifest.items():
        if hashlib.sha256(archive.read(relative)).hexdigest() != digest:
            raise SystemExit('Archive verification failed')
report = {'archive': output.name, 'sha256': hashlib.sha256(output.read_bytes()).hexdigest(), 'bytes': output.stat().st_size,
          'files': len(manifest), 'entries': manifest, 'includes_secrets_or_data': False, 'deployed': False}
output.with_suffix('.manifest.json').write_text(json.dumps(report, indent=2) + '\n', encoding='utf-8')
print(json.dumps({key:value for key,value in report.items() if key != 'entries'}, indent=2))
