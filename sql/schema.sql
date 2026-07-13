-- Inquiry Tracker + Email Inbox schema
CREATE DATABASE IF NOT EXISTS inquiry_tracker
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE inquiry_tracker;

-- Task 1: stored emails
CREATE TABLE IF NOT EXISTS emails (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id    VARCHAR(255)    NOT NULL,
  from_address  VARCHAR(512)    NOT NULL,
  subject       VARCHAR(998)    NOT NULL DEFAULT '',
  email_date    DATETIME        NULL,
  body_preview  TEXT            NULL,
  attachments   JSON            NULL,
  fetched_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_emails_message_id (message_id),
  KEY idx_emails_from (from_address(191)),
  KEY idx_emails_subject (subject(191)),
  KEY idx_emails_date (email_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tasks 2 & 3: inquiries
CREATE TABLE IF NOT EXISTS inquiries (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_name  VARCHAR(150)    NOT NULL,
  email          VARCHAR(255)    NOT NULL,
  phone          VARCHAR(50)     NULL,
  status         ENUM('new','in-progress','closed') NOT NULL DEFAULT 'new',
  notes          TEXT            NULL,
  created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  -- Filter by status + sort by created_at (list endpoint hot path)
  KEY idx_inquiries_status_created (status, created_at),
  -- Sort-only path when no status filter
  KEY idx_inquiries_created (created_at),
  -- Exact / prefix lookups on email
  KEY idx_inquiries_email (email),
  -- Name filter / sort
  KEY idx_inquiries_customer_name (customer_name),
  -- Fast keyword search (MATCH AGAINST) instead of leading-wildcard LIKE
  FULLTEXT KEY ft_inquiries_search (customer_name, email, notes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;