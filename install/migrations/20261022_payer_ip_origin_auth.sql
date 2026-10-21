-- Add the explicitly selected trust mode without changing existing capture or merchant switches.
-- origin_auth requires verified HTTPS origin transport and Nginx-generated FastCGI proofs.
-- The origin secret belongs only in the server's private Nginx configuration and ESA, never this table.
INSERT IGNORE INTO `pre_config` (k,v) VALUES ('payer_ip_trust_mode','cidr');
