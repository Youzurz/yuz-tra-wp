terraform {
  required_version = ">= 1.6, < 2.0"
  required_providers {
    github = {
      source  = "integrations/github"
      version = "6.13.0"
    }
  }
}

# Credentials come from GITHUB_TOKEN, never tfvars or source control.
provider "github" {
  owner = "Youzurz"
}

variable "release_reviewer" {
  description = "Existing GitHub login with repository access; no invented reviewer."
  type        = string
}

variable "independent_reviews" {
  description = "Set to 1 only when a second maintainer is available. PR and checks remain mandatory at 0."
  type        = number
  default     = 0
  validation {
    condition     = contains([0, 1, 2], var.independent_reviews)
    error_message = "Use 0, 1 or 2 available independent reviewers."
  }
}

data "github_user" "reviewer" {
  username = var.release_reviewer
}

resource "github_repository_ruleset" "main" {
  repository  = "yuz-tra-wp"
  name        = "yuz-required-release-gates"
  target      = "branch"
  enforcement = "active"
  conditions {
    ref_name {
      include = ["refs/heads/main"]
      exclude = []
    }
  }
  rules {
    deletion         = true
    non_fast_forward = true
    pull_request {
      required_approving_review_count   = var.independent_reviews
      dismiss_stale_reviews_on_push     = true
      required_review_thread_resolution = true
    }
    required_status_checks {
      strict_required_status_checks_policy = true
      required_check {
        context        = "Release gate"
        integration_id = 15368 # GitHub Actions app, observed on this repository's checks.
      }
    }
  }
  lifecycle {
    prevent_destroy = true
  }
}

resource "github_repository_ruleset" "tags" {
  repository  = "yuz-tra-wp"
  name        = "yuz-immutable-version-tags"
  target      = "tag"
  enforcement = "active"
  conditions {
    ref_name {
      include = ["refs/tags/v*"]
      exclude = []
    }
  }
  rules {
    update   = true
    deletion = true
  }
  lifecycle {
    prevent_destroy = true
  }
}

resource "github_repository_environment" "release" {
  repository          = "yuz-tra-wp"
  environment         = "release"
  can_admins_bypass   = false
  prevent_self_review = var.independent_reviews > 0
  reviewers {
    users = [tonumber(data.github_user.reviewer.id)]
  }
  deployment_branch_policy {
    protected_branches     = true
    custom_branch_policies = false
  }
  lifecycle {
    prevent_destroy = true
  }
}
