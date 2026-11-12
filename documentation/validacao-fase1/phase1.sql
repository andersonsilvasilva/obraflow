-- PROTÓTIPO DESCARTÁVEL do esquema da Fase 1 (FASE1_MODELO.md), apenas para validação em instância
-- MySQL temporária. NÃO são as migrations do projeto.
DROP DATABASE IF EXISTS obraflow_sandbox;
CREATE DATABASE obraflow_sandbox CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE obraflow_sandbox;

CREATE TABLE failed_jobs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid VARCHAR(255) NOT NULL,
  connection TEXT NOT NULL, queue TEXT NOT NULL,
  payload LONGTEXT NOT NULL, exception LONGTEXT NOT NULL,
  failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY failed_jobs_uuid_unique (uuid)
) ENGINE=InnoDB;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) COLLATE utf8mb4_0900_as_ci NOT NULL,
  email_verified_at TIMESTAMP NULL,
  password VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  last_login_at TIMESTAMP NULL,
  remember_token VARCHAR(100) NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY users_uuid_unique (uuid),
  UNIQUE KEY users_email_unique (email),
  CONSTRAINT chk_users_status CHECK (status IN ('active','blocked'))
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
  email VARCHAR(190) NOT NULL PRIMARY KEY,
  token VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NULL
) ENGINE=InnoDB;

CREATE TABLE tenants (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  name VARCHAR(150) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  legal_name VARCHAR(200) NULL, document_number VARCHAR(20) NULL,
  email VARCHAR(190) NULL, phone VARCHAR(30) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  timezone VARCHAR(64) NOT NULL DEFAULT 'America/Sao_Paulo',
  locale VARCHAR(10) NOT NULL DEFAULT 'pt_BR',
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL, deleted_at TIMESTAMP NULL,
  UNIQUE KEY tenants_uuid_unique (uuid),
  UNIQUE KEY tenants_slug_unique (slug),
  CONSTRAINT chk_tenants_status CHECK (status IN ('trial','active','suspended','canceled'))
) ENGINE=InnoDB;

CREATE TABLE tenant_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  membership_status VARCHAR(20) NOT NULL DEFAULT 'active',
  joined_at TIMESTAMP NULL, last_used_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY tenant_users_tenant_user_unique (tenant_id, user_id),
  KEY tenant_users_user_status_idx (user_id, membership_status),
  CONSTRAINT fk_tu_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_tu_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_tu_status CHECK (membership_status IN ('active','suspended','removed'))
) ENGINE=InnoDB;

CREATE TABLE tenant_domains (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  domain VARCHAR(190) NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  primary_marker TINYINT GENERATED ALWAYS AS (IF(is_primary = 1, 1, NULL)) VIRTUAL,
  verified_at TIMESTAMP NULL, created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY td_domain_unique (domain),
  UNIQUE KEY td_one_primary (tenant_id, primary_marker),
  CONSTRAINT fk_td_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE tenant_settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  setting_key VARCHAR(100) NOT NULL,
  setting_value JSON NOT NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY ts_tenant_key_unique (tenant_id, setting_key),
  CONSTRAINT fk_ts_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(125) NOT NULL,
  guard_name VARCHAR(125) NOT NULL,
  module VARCHAR(60) NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY permissions_name_guard_unique (name, guard_name)
) ENGINE=InnoDB;

CREATE TABLE roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(125) NOT NULL,
  guard_name VARCHAR(125) NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY roles_tenant_name_guard_unique (tenant_id, name, guard_name),
  UNIQUE KEY roles_tenant_id_unique (tenant_id, id),
  CONSTRAINT fk_roles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE model_has_permissions (
  permission_id BIGINT UNSIGNED NOT NULL,
  model_type VARCHAR(255) NOT NULL,
  model_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (tenant_id, permission_id, model_id, model_type),
  KEY mhp_model_idx (model_id, model_type),
  KEY mhp_tenant_model_idx (tenant_id, model_id),
  CONSTRAINT fk_mhp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_mhp_member FOREIGN KEY (tenant_id, model_id) REFERENCES tenant_users (tenant_id, user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_mhp_type CHECK (model_type = 'user')
) ENGINE=InnoDB;

CREATE TABLE model_has_roles (
  role_id BIGINT UNSIGNED NOT NULL,
  model_type VARCHAR(255) NOT NULL,
  model_id BIGINT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (tenant_id, role_id, model_id, model_type),
  KEY mhr_model_idx (model_id, model_type),
  KEY mhr_tenant_model_idx (tenant_id, model_id),
  CONSTRAINT fk_mhr_role FOREIGN KEY (tenant_id, role_id) REFERENCES roles (tenant_id, id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_mhr_member FOREIGN KEY (tenant_id, model_id) REFERENCES tenant_users (tenant_id, user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_mhr_type CHECK (model_type = 'user')
) ENGINE=InnoDB;

CREATE TABLE role_has_permissions (
  permission_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (permission_id, role_id),
  CONSTRAINT fk_rhp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_rhp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE personal_access_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tokenable_type VARCHAR(255) NOT NULL,
  tokenable_id BIGINT UNSIGNED NOT NULL,
  name TEXT NOT NULL,
  token VARCHAR(64) NOT NULL,
  abilities TEXT NULL,
  last_used_at TIMESTAMP NULL, expires_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  tenant_id BIGINT UNSIGNED NULL,
  UNIQUE KEY pat_token_unique (token),
  KEY pat_tokenable_idx (tokenable_type, tokenable_id),
  KEY pat_expires_idx (expires_at),
  KEY pat_tenant_tokenable_idx (tenant_id, tokenable_id),
  CONSTRAINT fk_pat_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_pat_member FOREIGN KEY (tenant_id, tokenable_id) REFERENCES tenant_users (tenant_id, user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_pat_type CHECK (tokenable_type = 'user')
) ENGINE=InnoDB;

CREATE TABLE user_invitations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(190) COLLATE utf8mb4_0900_as_ci NOT NULL,
  invited_by BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  expires_at TIMESTAMP NOT NULL,
  accepted_at TIMESTAMP NULL,
  accepted_user_id BIGINT UNSIGNED NULL,
  revoked_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  pending_email VARCHAR(190) COLLATE utf8mb4_0900_as_ci GENERATED ALWAYS AS (IF(status = 'pending', email, NULL)) VIRTUAL,
  UNIQUE KEY ui_token_hash_unique (token_hash),
  UNIQUE KEY ui_one_pending (tenant_id, pending_email),
  KEY ui_tenant_invited_by_idx (tenant_id, invited_by),
  KEY ui_tenant_role_idx (tenant_id, role_id),
  KEY ui_tenant_accepted_idx (tenant_id, accepted_user_id),
  CONSTRAINT fk_ui_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_ui_inviter FOREIGN KEY (tenant_id, invited_by) REFERENCES tenant_users (tenant_id, user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_ui_role FOREIGN KEY (tenant_id, role_id) REFERENCES roles (tenant_id, id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_ui_accepted FOREIGN KEY (tenant_id, accepted_user_id) REFERENCES tenant_users (tenant_id, user_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_ui_status CHECK (status IN ('pending','accepted','expired','revoked')),
  CONSTRAINT chk_ui_accepted CHECK (status <> 'accepted' OR (accepted_at IS NOT NULL AND accepted_user_id IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uuid CHAR(36) NOT NULL,
  tenant_id BIGINT UNSIGNED NULL,
  user_id BIGINT UNSIGNED NULL,
  event VARCHAR(60) NOT NULL,
  entity_type VARCHAR(100) NULL,
  entity_id BIGINT UNSIGNED NULL,
  old_values JSON NULL, new_values JSON NULL, metadata JSON NULL,
  ip_address VARBINARY(16) NULL,
  user_agent VARCHAR(255) NULL,
  request_id CHAR(36) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY al_uuid_unique (uuid),
  KEY al_tenant_created_idx (tenant_id, created_at),
  KEY al_tenant_entity_idx (tenant_id, entity_type, entity_id),
  KEY al_user_created_idx (user_id, created_at),
  KEY al_event_created_idx (event, created_at),
  CONSTRAINT fk_al_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_al_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB;
