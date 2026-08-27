provider "aws" {
  region = var.aws_region
}

resource "aws_s3_bucket" "crm_files" {
  bucket = var.s3_bucket_name
}

resource "aws_s3_bucket_versioning" "crm_files" {
  bucket = aws_s3_bucket.crm_files.id
  versioning_configuration { status = "Enabled" }
}

resource "aws_s3_bucket_public_access_block" "crm_files" {
  bucket                  = aws_s3_bucket.crm_files.id
  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_server_side_encryption_configuration" "crm_files" {
  bucket = aws_s3_bucket.crm_files.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_db_instance" "crm" {
  identifier                = var.db_identifier
  engine                    = "postgres"
  engine_version            = "16"
  instance_class            = var.db_instance_class
  allocated_storage         = var.db_allocated_storage
  db_name                   = var.db_name
  username                  = var.db_username
  password                  = var.db_password
  publicly_accessible       = false
  storage_encrypted         = true
  skip_final_snapshot       = var.skip_final_snapshot
  final_snapshot_identifier = var.skip_final_snapshot ? null : var.final_snapshot_identifier
  backup_retention_period   = 7
}

resource "aws_elasticache_replication_group" "redis" {
  replication_group_id       = var.redis_identifier
  description                = "CRM Redis and Horizon"
  engine                     = "redis"
  node_type                  = var.redis_node_type
  num_cache_clusters         = 1
  automatic_failover_enabled = false
  at_rest_encryption_enabled = true
  transit_encryption_enabled = true
}
