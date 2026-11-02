CREATE TABLE IF NOT EXISTS users (
    id BIGINT NOT NULL PRIMARY KEY,
    username VARCHAR(64) NULL,
    first_name VARCHAR(128) NULL,
    balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    state VARCHAR(32) NULL,
    state_data VARCHAR(255) NULL,
    buy_token VARCHAR(16) NULL,
    is_banned TINYINT(1) NOT NULL DEFAULT 0,
    is_reseller TINYINT(1) NOT NULL DEFAULT 0,
    is_held TINYINT(1) NOT NULL DEFAULT 0,
    hold_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    sort INT NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    price DECIMAL(14,2) NOT NULL,
    reseller_price DECIMAL(14,2) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cat (category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    key_value TEXT NOT NULL,
    status ENUM('available','sold') NOT NULL DEFAULT 'available',
    order_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sold_at DATETIME NULL,
    INDEX idx_prod_status (product_id, status),
    INDEX idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(14,2) NOT NULL,
    total DECIMAL(14,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS deposits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    chain VARCHAR(10) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    pay_amount DECIMAL(24,8) NOT NULL,
    rate DECIMAL(18,2) NULL,
    pay_address VARCHAR(100) NULL,
    credited DECIMAL(14,2) NULL,
    status ENUM('pending','paid','expired','cancelled') NOT NULL DEFAULT 'pending',
    txid VARCHAR(100) NULL,
    claim_txid VARCHAR(100) NULL,
    review TINYINT(1) NOT NULL DEFAULT 0,
    review_idx INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    paid_at DATETIME NULL,
    INDEX idx_match (chain, status, created_at),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chain_txs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chain VARCHAR(10) NOT NULL,
    txid VARCHAR(100) NOT NULL,
    idx INT NOT NULL DEFAULT 0,
    amount VARCHAR(80) NOT NULL,
    from_addr VARCHAR(100) NULL,
    deposit_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tx (chain, txid, idx)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS txid_claims (
    id INT AUTO_INCREMENT PRIMARY KEY,
    chain VARCHAR(10) NOT NULL,
    txid VARCHAR(100) NOT NULL,
    user_id BIGINT NOT NULL,
    deposit_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_claim (chain, txid, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(64) NOT NULL PRIMARY KEY,
    v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT NOT NULL,
    type VARCHAR(20) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    balance_after DECIMAL(14,2) NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
