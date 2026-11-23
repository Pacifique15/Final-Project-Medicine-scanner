-- Fresh SQLite schema for Medicine Scanner and appointment booking.
-- The running API applies additive migrations and preserves existing data.

CREATE TABLE users(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  full_name TEXT NOT NULL,
  username TEXT NOT NULL,
  email TEXT NOT NULL,
  phone TEXT NOT NULL DEFAULT '',
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'patient' CHECK(role IN ('patient','doctor','admin')),
  account_status TEXT NOT NULL DEFAULT 'active' CHECK(account_status IN ('active','disabled')),
  created_at INTEGER NOT NULL
);
CREATE UNIQUE INDEX users_username_unique ON users(LOWER(username));
CREATE UNIQUE INDEX users_email_unique ON users(LOWER(email));
CREATE INDEX users_account_status ON users(account_status);

CREATE TABLE specialties(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE
);
CREATE TABLE patients(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL UNIQUE REFERENCES users(id),
  created_at INTEGER NOT NULL
);
CREATE TABLE doctors(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER UNIQUE REFERENCES users(id),
  name TEXT NOT NULL,
  specialty_id INTEGER NOT NULL REFERENCES specialties(id),
  bio TEXT NOT NULL DEFAULT '',
  photo_url TEXT NOT NULL DEFAULT '',
  consultation_fee TEXT NOT NULL DEFAULT '',
  is_active INTEGER NOT NULL DEFAULT 1,
  is_available INTEGER NOT NULL DEFAULT 1,
  unavailable_reason TEXT NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL
);
CREATE TABLE doctor_availability(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  doctor_id INTEGER NOT NULL REFERENCES doctors(id),
  weekday INTEGER NOT NULL CHECK(weekday BETWEEN 0 AND 6),
  start_time TEXT NOT NULL,
  end_time TEXT NOT NULL,
  slot_minutes INTEGER NOT NULL DEFAULT 30 CHECK(slot_minutes BETWEEN 10 AND 180),
  UNIQUE(doctor_id,weekday)
);
CREATE TABLE doctor_unavailable_dates(
  doctor_id INTEGER NOT NULL REFERENCES doctors(id),
  date TEXT NOT NULL,
  reason TEXT NOT NULL DEFAULT '',
  PRIMARY KEY(doctor_id,date)
);
CREATE TABLE appointments(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  patient_id INTEGER NOT NULL REFERENCES patients(user_id),
  doctor_id INTEGER NOT NULL REFERENCES doctors(id),
  specialty_id INTEGER NOT NULL REFERENCES specialties(id),
  patient_name TEXT NOT NULL,
  symptoms TEXT NOT NULL,
  additional_info TEXT NOT NULL DEFAULT '',
  appointment_date TEXT NOT NULL,
  appointment_time TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'PENDING' CHECK(status IN ('PENDING','ACCEPTED','REJECTED','REDIRECTED','CANCELLED','COMPLETED')),
  rejection_reason TEXT NOT NULL DEFAULT '',
  alternative_doctor_id INTEGER REFERENCES doctors(id),
  patient_email TEXT NOT NULL,
  patient_phone TEXT NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL DEFAULT 0
);
CREATE UNIQUE INDEX appointments_active_slot_unique ON appointments(doctor_id,appointment_date,appointment_time) WHERE status IN ('PENDING','ACCEPTED');
CREATE INDEX appointments_patient_status ON appointments(patient_id,status,appointment_date);
CREATE INDEX appointments_doctor_status ON appointments(doctor_id,status,appointment_date);
CREATE TABLE notifications(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id),
  appointment_id INTEGER REFERENCES appointments(id),
  type TEXT NOT NULL,
  message TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  read_at INTEGER
);
INSERT INTO specialties(name) VALUES
  ('General Medicine'),('Cardiology'),('Dermatology'),('Ophthalmology'),
  ('Pediatrics'),('Orthopedics'),('Gynecology'),('Psychiatry'),('Dentistry'),('Pharmacy');

CREATE TABLE scans(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  t INTEGER,
  code TEXT,
  name TEXT,
  batch TEXT,
  expiry TEXT,
  status TEXT,
  demo INTEGER DEFAULT 0,
  device_ack TEXT NOT NULL DEFAULT 'NOT_CONFIRMED',
  led_action TEXT NOT NULL DEFAULT 'NOT_CONFIRMED',
  buzzer_action TEXT NOT NULL DEFAULT 'NOT_CONFIRMED',
  alert_message TEXT NOT NULL DEFAULT ''
);
CREATE TABLE medicines(
  code TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  batch TEXT NOT NULL DEFAULT '',
  expiry TEXT NOT NULL
);

CREATE TABLE activity_log(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  t INTEGER,
  actor TEXT,
  action TEXT,
  details TEXT
);
CREATE TABLE chat_messages(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  t INTEGER,
  sid TEXT,
  phone TEXT,
  role TEXT,
  content TEXT
);
CREATE TABLE patient_profiles(
  pkey TEXT PRIMARY KEY,
  name TEXT,
  age TEXT,
  allergies TEXT,
  medicines TEXT
);
CREATE TABLE ai_feedback(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  t INTEGER,
  sid TEXT,
  message TEXT,
  rating INTEGER
);
CREATE TABLE ai_knowledge(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  question TEXT,
  answer TEXT,
  created_at INTEGER
);

CREATE TABLE auth_login_attempts(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  identity_hash TEXT NOT NULL,
  ip_hash TEXT NOT NULL,
  attempted_at INTEGER NOT NULL
);
CREATE INDEX auth_login_attempts_window ON auth_login_attempts(identity_hash,ip_hash,attempted_at);
CREATE TABLE auth_remember_tokens(
  selector TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id),
  token_hash TEXT NOT NULL,
  expires_at INTEGER NOT NULL,
  created_at INTEGER NOT NULL
);
CREATE INDEX auth_remember_tokens_user ON auth_remember_tokens(user_id,created_at);
CREATE TABLE password_reset_tokens(
  token_hash TEXT PRIMARY KEY,
  user_id INTEGER NOT NULL REFERENCES users(id),
  expires_at INTEGER NOT NULL,
  created_at INTEGER NOT NULL
);
CREATE INDEX password_reset_tokens_user ON password_reset_tokens(user_id,expires_at);
CREATE TABLE password_reset_attempts(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  identity_hash TEXT NOT NULL,
  ip_hash TEXT NOT NULL,
  requested_at INTEGER NOT NULL
);
CREATE INDEX password_reset_attempts_window ON password_reset_attempts(identity_hash,ip_hash,requested_at);

INSERT INTO medicines(code,name,batch,expiry) VALUES
  ('MED001','Paracetamol 500mg','P0012026','2028-12-31'),
  ('MED003','Ibuprofen 400mg','I2209','2026-03-01');
