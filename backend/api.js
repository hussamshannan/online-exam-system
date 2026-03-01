/**
 * api.js — Shared API client for the Online Exam System.
 * Include on every HTML page:  <script src="backend/api.js"></script>
 */
const API = (() => {
  // Adjust BASE if your project lives at a sub-path, e.g. '/online-exam/backend'
  const BASE = 'backend';

  function getToken() {
    return localStorage.getItem('authToken');
  }

  async function request(endpoint, method = 'GET', body = null) {
    const opts = {
      method,
      headers: { 'Content-Type': 'application/json' },
    };
    const token = getToken();
    if (token) opts.headers['Authorization'] = 'Bearer ' + token;
    if (body !== null) opts.body = JSON.stringify(body);

    let res;
    try {
      res = await fetch(BASE + '/' + endpoint, opts);
    } catch (e) {
      throw new Error('تعذّر الاتصال بالخادم. تأكد من تشغيل XAMPP/MAMP.');
    }

    const data = await res.json().catch(() => ({ success: false, message: 'استجابة غير صحيحة من الخادم' }));

    // Auto-redirect to login on 401
    if (res.status === 401) {
      localStorage.removeItem('authToken');
      localStorage.removeItem('currentUser');
      if (!window.location.pathname.endsWith('index.html') &&
          !window.location.pathname.endsWith('/')) {
        window.location.href = 'index.html';
      }
    }

    return data;
  }

  return {
    // ── Auth ──────────────────────────────────────────────────────────────────
    login(role, name, password) {
      return request('auth.php', 'POST', { action: 'login', role, name, password });
    },
    logout() {
      return request('auth.php', 'POST', { action: 'logout' });
    },
    me() {
      return request('auth.php', 'GET');
    },

    // ── Students ──────────────────────────────────────────────────────────────
    getStudents() {
      return request('students.php', 'GET');
    },
    addStudent(data) {
      return request('students.php', 'POST', { action: 'create', ...data });
    },
    deleteStudent(id) {
      return request('students.php', 'POST', { action: 'delete', id });
    },
    toggleStudentStatus(id) {
      return request('students.php', 'POST', { action: 'toggle_status', id });
    },

    // ── Exams ─────────────────────────────────────────────────────────────────
    getExam() {
      return request('exams.php', 'GET');
    },
    saveExam(data) {
      return request('exams.php', 'POST', { action: 'save', ...data });
    },
    deleteExam(id) {
      return request('exams.php', 'POST', { action: 'delete', id });
    },
    deleteAllExams() {
      return request('exams.php', 'POST', { action: 'delete_all' });
    },

    // ── Sessions ──────────────────────────────────────────────────────────────
    startSession(examId) {
      return request('sessions.php', 'POST', { action: 'start', exam_id: examId });
    },
    logActivity(sessionId, message) {
      // Non-blocking fire-and-forget
      return request('sessions.php', 'POST', { action: 'log', session_id: sessionId, message });
    },
    submitExam(sessionId, answers) {
      return request('sessions.php', 'POST', { action: 'submit', session_id: sessionId, answers });
    },
    getResult() {
      return request('sessions.php', 'GET');
    },

    // ── Admin logs ────────────────────────────────────────────────────────────
    getLogs() {
      return request('logs.php', 'GET');
    },
    clearLogs() {
      return request('logs.php', 'DELETE');
    },
  };
})();
