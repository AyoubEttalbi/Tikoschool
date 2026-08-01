import axios from "axios";
window.axios = axios;

window.axios.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";

// Set withCredentials to true for CSRF handling
window.axios.defaults.withCredentials = true;

/**
 * Echo is NOT imported here any more.
 *
 * `import "./echo"` constructed a Pusher client at module load, so every visitor —
 * including anyone sitting on the login page — downloaded laravel-echo + pusher-js in the
 * entry chunk and opened a websocket. Components that need realtime now call
 * `getEcho()` from "@/echo", which loads and connects on first use.
 */
