# Execute through gitlab-rails runner on the authorised GitLab server.
# Never print the generated runner credential. Transfer the 0600 output securely.
project = Project.find_by_full_path('yuz/yuz-tra-wp')
raise 'Expected YUZ-TRA project not found' unless project && project.id == 20
admins = User.where(admin: true, state: 'active').limit(2).to_a
raise 'Expected one explicit active administration identity' unless admins.length == 1
actor = admins.first
runner = project.runners.find_by(description: 'yuz-tra-isolated-docker')
unless runner
  response = Ci::Runners::CreateRunnerService.new(user: actor, params: {
    runner_type: 'project_type', scope: project, description: 'yuz-tra-isolated-docker',
    tag_list: ['yuz-tra-ci'], run_untagged: false, locked: true,
    maximum_timeout: 1200, access_level: 'not_protected'
  }).execute
  raise response.message.to_s unless response.success?
  runner = response.payload[:runner]
end
raise 'Runner is not exclusive to this project' unless runner.projects.pluck(:id) == [project.id]
raise 'Missing runner credential' unless runner.token.is_a?(String) && runner.token.length >= 20
destination = '/tmp/yuz-tra-runner-config.toml'
raise 'Refusing to overwrite an existing credential file' if File.exist?(destination)
File.open(destination, File::WRONLY | File::CREAT | File::EXCL, 0600) do |file|
  file.write <<~TOML
    concurrent = 1
    check_interval = 3
    [[runners]]
      name = "yuz-tra-isolated-docker"
      url = "https://git.youzurz.com"
      id = #{runner.id}
      token = #{runner.token.to_json}
      executor = "docker"
      limit = 1
      [runners.docker]
        image = "node:22-bookworm"
        privileged = false
        disable_cache = true
        volumes = []
        allowed_images = ["node:22-bookworm", "php:8.3-cli"]
        allowed_services = []
        pull_policy = ["always"]
        memory = "1g"
        cpus = "2"
        dns = ["10.77.0.5", "1.1.1.1"]
  TOML
end
puts({runner_id: runner.id, project_id: project.id, actor_id: actor.id,
      credential_written_privately: true}.to_json)
