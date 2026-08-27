variable "aws_region" {
  type    = string
  default = "us-east-1"
}

variable "s3_bucket_name" {
  type = string
}

variable "db_identifier" {
  type    = string
  default = "crm-postgres"
}

variable "db_instance_class" {
  type    = string
  default = "db.t4g.micro"
}

variable "db_allocated_storage" {
  type    = number
  default = 20
}

variable "db_name" {
  type    = string
  default = "crm"
}

variable "db_username" {
  type      = string
  sensitive = true
}

variable "db_password" {
  type      = string
  sensitive = true
}

variable "skip_final_snapshot" {
  type    = bool
  default = false
}

variable "final_snapshot_identifier" {
  type    = string
  default = "crm-postgres-final"
}

variable "redis_identifier" {
  type    = string
  default = "crm-redis"
}

variable "redis_node_type" {
  type    = string
  default = "cache.t4g.micro"
}
