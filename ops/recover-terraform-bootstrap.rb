# One-off recovery for the credential created but never exported by the first
# bootstrap attempt. No other token or membership is touched.
failed = PersonalAccessToken.find(8)
raise 'Unexpected token' unless failed.name == 'yuz-tra-terraform-bootstrap' && failed.user_id == 41
raise 'Credential already staged: do not revoke' if File.exist?('/tmp/yuz-tra-terraform-auth.json')
failed.revoke! unless failed.revoked?
puts 'Revoked unexported bootstrap token 8'
