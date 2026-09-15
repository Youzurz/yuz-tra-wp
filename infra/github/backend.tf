terraform {
  # GitLab HTTP state provides remote locking. Credentials must be injected
  # through TF_HTTP_USERNAME / TF_HTTP_PASSWORD, never -backend-config secrets.
  backend "http" {}
}
