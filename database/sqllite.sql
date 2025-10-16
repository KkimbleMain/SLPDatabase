PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE,
    password_hash TEXT,
    role TEXT DEFAULT 'user',
    first_name TEXT,
    last_name TEXT,
    email TEXT,
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS students (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id TEXT,                 -- optional external id shown in UI
    first_name TEXT,
    last_name TEXT,
    grade TEXT,
    gender TEXT,
    date_of_birth TEXT,
    primary_language TEXT,
    service_frequency TEXT,
    assigned_therapist INTEGER,      -- FK -> users(id)
    archived INTEGER DEFAULT 0,      -- 0 = active, 1 = archived
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (assigned_therapist) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS initial_evaluations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    Reason_for_Referral TEXT,
    Background_Information TEXT,
    Assessment_Results TEXT,
    Recommendations TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS session_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    session_date TEXT,
    Duration_minutes INTEGER,
    Session_Type TEXT,
    Individual_Objectives_Targeted TEXT,
    Activities_Materials_Used TEXT,
    Student_Response_Performance TEXT,
    Plan_for_Next_Session TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS discharge_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    summary_data TEXT,
    Summary_of_Services_Provided TEXT,
    Goals_Achieved TEXT,
    Reason_for_Discharge TEXT,
    Follow_up_Recommendations TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS goals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    student_id INTEGER NOT NULL,
    Long_Term_Goals TEXT,
    Short_Term_Objectives TEXT,
    Intervention_Strategies TEXT,
    Measurement_Criteria TEXT,
    goal_date TEXT,
    therapist_id INTEGER,
    status TEXT DEFAULT 'active',
    target_date TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT,
    meta TEXT,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (therapist_id) REFERENCES users(id) ON DELETE SET NULL
);

-- progress_updates table removed (progress reporting feature deprecated/removed)

-- Activity log for dashboard recent activity
CREATE TABLE IF NOT EXISTS activity_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT,            -- e.g., student.created, student.updated, document.created
    user_id INTEGER,      -- actor
    student_id INTEGER,    -- related student
    title TEXT,
    details TEXT,         -- optional JSON/details
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
);

-- Password resets table (if used)
CREATE TABLE IF NOT EXISTS password_resets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    username TEXT,
    token TEXT,
    expires_at TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_students_assigned ON students(assigned_therapist);
CREATE INDEX IF NOT EXISTS idx_students_archived ON students(archived);
CREATE INDEX IF NOT EXISTS idx_goals_student ON goals(student_id);
CREATE INDEX IF NOT EXISTS idx_activity_student ON activity_log(student_id);