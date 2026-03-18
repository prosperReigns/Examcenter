# Examcenter Frontend (React)

This folder recreates the Examcenter landing page UI in React while linking to the existing PHP backend.

## Local development

```bash
npm install
npm run dev
```

## Backend linking

By default the frontend links to backend pages using relative URLs (e.g. `login.php` and
`student/register.php`). If the backend is hosted on a different origin, define
`VITE_BACKEND_URL` when running the frontend:

```bash
VITE_BACKEND_URL="http://localhost/EXAMCENTER" npm run dev
```
