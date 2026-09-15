# Run through GitLab Rails runner on H86. Never print the generated credential.
require 'json'
path = '/tmp/yuz-tra-terraform-auth.json'
raise 'Credential staging file already exists' if File.exist?(path)
project = Project.find(20)
raise 'Unexpected project' unless project.full_path.downcase == 'yuz/yuz-tra-wp'
raise 'State project must remain private' unless project.private?
actor = User.find(1)
raise 'Expected active administrator' unless actor.admin? && actor.active?
name = 'yuz-tra-terraform-bootstrap'
raise 'Existing bootstrap token requires explicit recovery' if PersonalAccessToken.active.where(name: name).exists?
result = ResourceAccessTokens::CreateService.new(actor, project, {
  name: name, scopes: ['api'], access_level: Gitlab::Access::MAINTAINER,
  expires_at: Date.current + 7,
  description: 'Scoped project 20 Terraform import; rotate before expiry'
}).execute
raise result.message unless result.success?
token = result.payload[:access_token]
raise 'Missing generated token' unless token && token.token.present?
File.open(path, File::WRONLY | File::CREAT | File::EXCL, 0600) do |file|
  file.write(JSON.generate(username: token.user.username, password: token.token))
end
puts JSON.generate(project_id: project.id, token_id: token.id, expires_at: token.expires_at, credential_written_privately: true)
