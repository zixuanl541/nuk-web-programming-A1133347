-- CrossProfit Pro — Database Schema
-- Run this file first: mysql -u root -p < schema.sql


-- ── Users ──────────────────────────────────────────────
CREATE TABLE users (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT DEFAULT NULL,
  username   VARCHAR(50)  NOT NULL UNIQUE,
  email      VARCHAR(100) NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,          -- bcrypt hashed
  role       ENUM('admin','seller') NOT NULL DEFAULT 'seller',
  status     ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ── Companies ──────────────────────────────────────────
CREATE TABLE companies (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  owner_id   INT NOT NULL,
  name       VARCHAR(100) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (owner_id) REFERENCES users(id)
);

ALTER TABLE users
  ADD CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;

-- ── Products ───────────────────────────────────────────
CREATE TABLE products (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  company_id    INT NOT NULL,
  name          VARCHAR(100) NOT NULL,
  weight        DECIMAL(8,2) NOT NULL DEFAULT 0,   -- grams
  cost          DECIMAL(10,2) NOT NULL DEFAULT 0,  -- in original currency
  currency      ENUM('RMB','USD','JPY','TWD') NOT NULL DEFAULT 'RMB',
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  selling_price DECIMAL(10,2) DEFAULT NULL,
  FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

-- ── Batches ────────────────────────────────────────────
CREATE TABLE batches (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NOT NULL,
  company_id     INT NOT NULL,
  batch_date     DATE NOT NULL,
  total_shipping DECIMAL(10,2) NOT NULL DEFAULT 0,  -- in original currency
  exchange_rate  DECIMAL(8,4)  NOT NULL DEFAULT 1,
  method         ENUM('equal','weight') NOT NULL DEFAULT 'equal',
  note           VARCHAR(255),
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)    REFERENCES users(id)      ON DELETE CASCADE,
  FOREIGN KEY (company_id) REFERENCES companies(id)  ON DELETE CASCADE
);

-- ── Inventory Items (one row per batch+product, after allocation) ──
CREATE TABLE inventory_items (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  batch_id           INT NOT NULL,
  product_id         INT NOT NULL,
  quantity           INT NOT NULL DEFAULT 1,
  remaining_quantity INT NOT NULL DEFAULT 1,
  unit_cost          DECIMAL(10,2) NOT NULL DEFAULT 0,  -- cost + allocated shipping per unit, in TWD
  created_at         DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (batch_id)   REFERENCES batches(id)  ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- ── Sales Records (one row per sale transaction) ──────────
CREATE TABLE sales_records (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT NOT NULL,
  sale_price    DECIMAL(10,2) NOT NULL,
  platform_fee  DECIMAL(10,2) NOT NULL DEFAULT 0,
  shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
  profit        DECIMAL(10,2) NOT NULL,
  sold_date     DATE NOT NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- ── Sale Items (line items per sale, supports cross-batch FIFO) ──
CREATE TABLE sale_items (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  sale_id           INT NOT NULL,
  inventory_item_id INT NOT NULL,
  quantity          INT NOT NULL,
  unit_cost         DECIMAL(10,2) NOT NULL,
  FOREIGN KEY (sale_id)           REFERENCES sales_records(id)  ON DELETE CASCADE,
  FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
);

-- ── Exchange Rates (historical rates, shared across all companies) ──
CREATE TABLE exchange_rates (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  currency   ENUM('RMB','USD','JPY') NOT NULL,
  rate       DECIMAL(8,4) NOT NULL,
  fetched_at DATETIME NOT NULL
);

-- ── Seed Data ──────────────────────────────────────────
-- Password: admin123 (bcrypt)
INSERT INTO users (username, email, password, role) VALUES
  ('admin',    'admin@crossprofit.tw',  '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uXutHN./K', 'admin'),
  ('seller01', 'seller@crossprofit.tw', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uXutHN./K', 'seller');
