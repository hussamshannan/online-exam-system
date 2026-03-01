# Online Exam System

A web-based examination platform with Arabic RTL interface, built with vanilla HTML/CSS/JavaScript on the frontend and PHP/MySQL on the backend.

## Features

- **Three user roles**: Admin, Teacher, Student
- **Exam creation**: Teachers can add questions manually or upload a JSON file
- **Question types**: Multiple choice (MCQ), True/False, Short answer
- **Auto-grading**: Answers are graded automatically on submission
- **Anti-cheat monitoring**: Camera/microphone access, tab-switch detection, copy/paste blocking, activity logging
- **Admin dashboard**: Manage students, exams, and view session logs
- **Arabic RTL interface**: Full right-to-left layout with Cairo font

## Tech Stack

| Layer    | Technology                        |
|----------|-----------------------------------|
| Frontend | HTML5, CSS3, Vanilla JavaScript   |
| Backend  | PHP 8+ with PDO (prepared statements) |
| Database | MySQL (`online_exam`)             |
| Auth     | Token-based (Bearer token)        |

## Setup

1. Place the project folder in your web server root (e.g. XAMPP `htdocs/`, or `/var/www/html/`)
2. Start MySQL
3. Edit `backend/config.php` with your database credentials:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');
   ```
4. Visit `http://localhost/online%20exam/backend/setup.php` once in your browser to create the database, tables, and seed data
5. Open `http://localhost/online%20exam/` to use the system

> The project must be served via a web server (Apache/Nginx) with PHP. Opening HTML files directly via `file://` will not work.

## Default Accounts

| Role    | Username  | Password    |
|---------|-----------|-------------|
| Admin   | admin     | admin123    |
| Teacher | teacher   | teacher123  |

Setup also seeds **8 sample students** (password: `student123`) and a sample exam.

## Project Structure

```
online exam/
├── index.html              Login page
├── student2.html           Student dashboard
├── go to exam.html         Exam-taking page (timer, camera, anti-cheat)
├── results.html            Score display after submission
├── teacher.html            Teacher exam builder (multi-step wizard)
├── exam make.html          Choose: upload JSON or add manually
├── add-questions.html      Manual question entry
├── exam-time.html          Set exam duration and save
├── upload-exam.html        Upload exam from JSON file
├── admin.html              Admin dashboard (card grid)
├── manage-students.html    Add/delete students
├── manage-exams.html       View/delete active exam
├── mange-user.html         View exam session logs
├── style.css               Unified stylesheet
└── backend/
    ├── config.php           Database credentials and constants
    ├── db.php               PDO singleton connection
    ├── helpers.php          CORS, JSON responses, auth utilities
    ├── setup.php            One-time DB + table + seed creation
    ├── auth.php             Login / logout / session check
    ├── students.php         Student CRUD (admin only)
    ├── exams.php            Exam save / get / delete
    ├── sessions.php         Start exam, log activity, submit & grade
    ├── logs.php             Admin: view/clear session logs
    └── api.js               Shared JS API client (window.API)
```

## How It Works

### Authentication

All pages verify the user session on load by calling `API.me()`. If the token is invalid or expired, the user is redirected to `index.html`. Tokens are stored in `localStorage` and sent as `Authorization: Bearer <token>` headers.

### Student Flow

1. **Login** (`index.html`) — Student logs in with name and password
2. **Dashboard** (`student2.html`) — Choose to take an exam or view results
3. **Exam** (`go to exam.html`) — Click "Start Exam" to begin a timed session
   - Camera and microphone are activated
   - Anti-cheat restrictions are enabled (tab-switch warnings, copy/paste blocking, keyboard shortcut blocking)
   - All suspicious activity is logged to the server
   - A countdown timer runs; auto-submits when time runs out
4. **Results** (`results.html`) — Displays score, percentage, and pass/fail status

### Teacher Flow

1. **Login** → Teacher dashboard (`teacher.html`)
2. **Create exam** — Two paths:
   - **Manual**: Fill in college/department/subject → add questions one by one → set duration → save
   - **Upload**: Go to `upload-exam.html` and upload a JSON file with the exam structure
3. The exam is saved to the database and becomes the active exam for students

### Admin Flow

1. **Login** → Admin dashboard (`admin.html`) — Card grid with quick navigation
2. **Manage Students** (`manage-students.html`) — Add new students, view the list, delete students
3. **Manage Exams** (`manage-exams.html`) — View the current active exam and its questions, delete it
4. **Monitor Students** (`mange-user.html`) — View all exam session logs, scores, suspicious activity counts

### Grading

Grading happens automatically when a student submits:
- **MCQ**: Case-insensitive letter match (a/b/c/d)
- **True/False**: String match ("true"/"false")
- **Short Answer**: Case-insensitive, trimmed string comparison

### Database Tables

| Table             | Purpose                                    |
|-------------------|--------------------------------------------|
| `users`           | All users (admin, teacher, student)         |
| `auth_tokens`     | Active login tokens with expiration         |
| `exams`           | Exam metadata (subject, duration, college)  |
| `questions`       | Questions linked to exams                   |
| `exam_sessions`   | Student exam attempts with scores           |
| `student_answers` | Individual question answers and grading     |
| `activity_logs`   | Anti-cheat event log (blur, blocked, etc.)  |

## Exam JSON Format

For uploading exams via JSON file:

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

## API Reference

All endpoints are called through the `window.API` object defined in `backend/api.js`.

| Method                              | HTTP        | Endpoint       | Role    |
|-------------------------------------|-------------|----------------|---------|
| `API.login(role, name, password)`   | POST        | auth.php       | Public  |
| `API.logout()`                      | POST        | auth.php       | Any     |
| `API.me()`                          | GET         | auth.php       | Any     |
| `API.getStudents()`                | GET         | students.php   | Admin   |
| `API.addStudent(data)`             | POST        | students.php   | Admin   |
| `API.deleteStudent(id)`            | POST        | students.php   | Admin   |
| `API.toggleStudentStatus(id)`      | POST        | students.php   | Admin   |
| `API.getExam()`                    | GET         | exams.php      | Any     |
| `API.saveExam(data)`              | POST        | exams.php      | Teacher |
| `API.deleteExam(id)`              | POST        | exams.php      | Admin   |
| `API.deleteAllExams()`            | POST        | exams.php      | Admin   |
| `API.startSession(examId)`        | POST        | sessions.php   | Student |
| `API.logActivity(sessionId, msg)` | POST        | sessions.php   | Any     |
| `API.submitExam(sessionId, answers)` | POST     | sessions.php   | Student |
| `API.getResult()`                 | GET         | sessions.php   | Student |
| `API.getLogs()`                   | GET         | logs.php       | Admin   |
| `API.clearLogs()`                | DELETE      | logs.php       | Admin   |
