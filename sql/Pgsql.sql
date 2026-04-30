CREATE TABLE IF NOT EXISTS typecho_mail (
  id SERIAL PRIMARY KEY,
  content text NOT NULL,
  sent int DEFAULT 0,
  created int DEFAULT 0,
  updated int DEFAULT 0,
  attempts int DEFAULT 0,
  last_error text NULL,
  next_retry int DEFAULT 0,
  locked_until int DEFAULT 0
);
