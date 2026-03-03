# نظام الامتحان الإلكتروني — Online Exam System

A web-based examination platform with a full Arabic RTL interface, supporting three user roles: **Student**, **Teacher**, and **Admin**.

---

## What Is This System?

This is an online exam platform designed for educational institutions. Students log in and take timed exams in the browser. Teachers create exams by adding questions manually or uploading a JSON file. Admins manage student accounts and monitor exam activity.

Key highlights:
- Exams are **auto-graded** instantly on submission (MCQ, True/False, Short Answer)
- A built-in **anti-cheat monitor** detects tab switching, copy/paste, and keyboard shortcuts
- The interface is fully in **Arabic (right-to-left)**

---

## Quick Start (First Run)

1. Place the project folder inside your web server root
   - XAMPP: `htdocs/online-exam-system-master/`
   - MAMP: `htdocs/online-exam-system-master/`

2. Start **Apache** and **MySQL** in XAMPP/MAMP

3. If needed, edit database credentials in `backend/config.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');   // XAMPP default is empty; MAMP default is 'root'
   ```

4. Open the login page in your browser:
   ```
   http://localhost/online-exam-system-master/
   ```

5. **The database is created automatically on first load.** A green banner will appear confirming that sample data has been added. No manual setup step is needed.

> The project must be served via Apache (XAMPP/MAMP). Opening HTML files directly with `file://` will **not** work.

---

## How to Log In — For Students

### Step 1 — Open the login page
Go to: `http://localhost/online-exam-system-master/`

### Step 2 — Choose your role
Select **طالب** (Student) from the dropdown at the top.

### Step 3 — Enter your name and password
- In the **Name** field, type your **full name exactly** as registered (e.g. `أحمد محمد علي`)
- In the **Password** field, type your password

### Step 4 — Click "تسجيل الدخول" (Login)
You will be taken to the student dashboard where you can start an exam.

### Sample Student Accounts (pre-loaded)

All sample students share the password: **`student123`**

| Full Name (type exactly) | Roll No. | College | Department |
|--------------------------|----------|---------|------------|
| أحمد محمد علي | 001 | كلية الهندسة | هندسة برمجيات |
| فاطمة حسن كريم | 002 | كلية الهندسة | هندسة برمجيات |
| عمر خالد سعيد | 003 | كلية الهندسة | هندسة برمجيات |
| زينب عبدالله ناصر | 004 | كلية الهندسة | هندسة برمجيات |
| محمد سامي جاسم | 005 | كلية الهندسة | هندسة برمجيات |
| نور علاء الدين | 006 | كلية الحاسوب | علوم الحاسوب |
| علي حسين رضا | 007 | كلية الحاسوب | علوم الحاسوب |
| سارة إبراهيم مجيد | 008 | كلية الطب | الطب البشري |

> **Important:** The name field is case-sensitive and must match exactly, including spaces and Arabic diacritics.

---

## Default Staff Accounts

| Role | Select in Dropdown | Any Name | Password |
|------|--------------------|----------|----------|
| Admin (مشرف) | مشرف | any name | `admin123` |
| Teacher (معلم) | معلم | any name | `teacher123` |

For Admin and Teacher accounts, the name you type is only used as a display name — any value is accepted as long as the password is correct.

---

## Student Guide — Taking an Exam

1. **Log in** as a student (see above)
2. You land on the **student dashboard** (`student2.html`)
3. Click **"ابدأ الامتحان"** (Start Exam) to go to the exam page
4. Click the **"ابدأ الامتحان"** button on the exam page to begin
   - Your **camera and microphone** will activate (required for proctoring)
   - A countdown timer starts
5. Answer all questions:
   - **MCQ**: select one option (أ / ب / ج / د)
   - **True/False**: select صح (True) or خطأ (False)
   - **Short Answer**: type your answer in the text box
6. Click **"انتهيت"** (Done) to submit — or the exam submits automatically when time runs out
7. You are redirected to the **results page** showing your score and percentage

### Anti-Cheat Rules During the Exam
The system monitors the following and logs them to the admin:
- Switching tabs or minimising the browser window
- Copy, paste, or cut actions
- Common keyboard shortcuts (Ctrl+C, Ctrl+V, etc.)
- Right-click context menu

Violations are visible to the admin but do **not** automatically fail the exam.

---

## Teacher Guide — Creating an Exam

1. Log in with role **معلم**, any name, password `teacher123`
2. You land on the **teacher dashboard** (`teacher.html`)
3. Choose how to create the exam:
   - **Manual**: fill in college, department, batch, subject → add questions one by one → set duration → save
   - **Upload JSON**: upload a structured JSON file (see format below)
4. The saved exam immediately becomes the active exam for students

### Exam JSON Upload Format

```json
{
  "college": "كلية الهندسة",
  "department": "هندسة برمجيات",
  "batch": "الدفعة الأولى",
  "subject": "برمجة 1",
  "duration": 60,
  "questions": [
    {
      "type": "mcq",
      "text": "ما هي لغة البرمجة الأكثر استخداماً؟",
      "mark": 2,
      "a": "Python",
      "b": "Java",
      "c": "JavaScript",
      "d": "C++",
      "correct": "c"
    },
    {
      "type": "tf",
      "text": "HTML هي لغة برمجة",
      "mark": 1,
      "correct": "false"
    },
    {
      "type": "short",
      "text": "ما هو اختصار CSS؟",
      "mark": 3,
      "answer": "Cascading Style Sheets"
    }
  ]
}
```

---

## Admin Guide

1. Log in with role **مشرف**, any name, password `admin123`
2. Admin dashboard cards:
   - **إدارة الطلاب** — Add new students or delete existing ones
   - **إدارة الامتحانات** — View the active exam and its questions, or delete it
   - **مراقبة الطلاب** — View all exam sessions: scores, submission times, and suspicious activity counts

---

## Grading

Grading is automatic on submission:

| Question Type | How Graded |
|---------------|-----------|
| MCQ | Letter match, case-insensitive (a/b/c/d) |
| True/False | String match ("true" / "false") |
| Short Answer | Case-insensitive, trimmed exact match |

---

## Project Structure

```
online-exam-system-master/
├── index.html              Login page (all roles)
├── student2.html           Student dashboard
├── go to exam.html         Exam-taking page (timer, camera, anti-cheat)
├── results.html            Score display after submission
├── teacher.html            Teacher exam builder
├── exam make.html          Choose: manual or JSON upload
├── add-questions.html      Manual question entry form
├── exam-time.html          Set exam duration and save
├── upload-exam.html        Upload exam from JSON file
├── admin.html              Admin dashboard
├── manage-students.html    Add/delete students
├── manage-exams.html       View/delete active exam
├── mange-user.html         View exam session logs and scores
├── style.css               Unified stylesheet (Cairo font, RTL)
└── backend/
    ├── config.php           Database credentials and constants
    ├── db.php               PDO singleton database connection
    ├── helpers.php          CORS, JSON responses, auth utilities
    ├── init.php             Auto-setup: creates DB, tables, and seeds data on first run
    ├── setup.php            Manual HTML setup page (alternative to auto-init)
    ├── auth.php             Login / logout / session check
    ├── students.php         Student CRUD (admin only)
    ├── exams.php            Exam save / get / delete
    ├── sessions.php         Start exam, log activity, submit and grade
    ├── logs.php             Admin: view/clear session logs
    └── api.js               Shared JS API client (window.API)
```

---

## Tech Stack

| Layer | Technology |
|-------|------------|
| Frontend | HTML5, CSS3, Vanilla JavaScript |
| Backend | PHP 8+ with PDO (prepared statements) |
| Database | MySQL — database name: `online_exam` |
| Auth | Bearer tokens in `localStorage`, 24-hour TTL |

---

## Database Tables

| Table | Purpose |
|-------|---------|
| `users` | All users (admin, teacher, student) |
| `auth_tokens` | Active login tokens with expiry |
| `exams` | Exam metadata (subject, duration, college) |
| `questions` | Questions linked to exams |
| `exam_sessions` | Student attempts with scores |
| `student_answers` | Individual answers and grading |
| `activity_logs` | Anti-cheat event log |

---

## API Reference

All endpoints are accessed through the `window.API` object in `backend/api.js`.

| Method | HTTP | Endpoint | Role |
|--------|------|----------|------|
| `API.login(role, name, password)` | POST | auth.php | Public |
| `API.logout()` | POST | auth.php | Any |
| `API.me()` | GET | auth.php | Any |
| `API.getStudents()` | GET | students.php | Admin |
| `API.addStudent(data)` | POST | students.php | Admin |
| `API.deleteStudent(id)` | POST | students.php | Admin |
| `API.toggleStudentStatus(id)` | POST | students.php | Admin |
| `API.getExam()` | GET | exams.php | Any |
| `API.saveExam(data)` | POST | exams.php | Teacher |
| `API.deleteExam(id)` | POST | exams.php | Admin |
| `API.deleteAllExams()` | POST | exams.php | Admin |
| `API.startSession(examId)` | POST | sessions.php | Student |
| `API.logActivity(sessionId, msg)` | POST | sessions.php | Any |
| `API.submitExam(sessionId, answers)` | POST | sessions.php | Student |
| `API.getResult()` | GET | sessions.php | Student |
| `API.getLogs()` | GET | logs.php | Admin |
| `API.clearLogs()` | DELETE | logs.php | Admin |
