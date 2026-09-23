/*
 * bootstrap.js — global, non-React setup that runs ONCE, before the app mounts
 * (it is imported at the top of app.jsx, so it executes first).
 *
 * It exposes axios on `window` so any module — or a legacy inline Blade script —
 * can reach `window.axios`, and sets the X-Requested-With header so Laravel treats
 * the calls as AJAX. This file deliberately contains no React or component code.
 */
import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
