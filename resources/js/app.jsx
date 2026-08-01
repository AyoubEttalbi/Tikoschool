import "../css/app.css";
import "./bootstrap";

import { createInertiaApp } from "@inertiajs/react";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import { createRoot } from "react-dom/client";
import ErrorBoundary from "@/Components/ErrorBoundary";

const appName = import.meta.env.VITE_APP_NAME || "Tikoschool";

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        // NOTE: a "default layout" block used to live here:
        //
        //     const page = resolvePageComponent(...);
        //     if (!page.layout) { page.layout = ... }
        //
        // resolvePageComponent is async, so `page` was a PROMISE and `.layout` was assigned
        // onto the promise object, which Inertia never reads — the branch was a no-op. Every
        // page already sets its own `Page.layout`, which is why nothing appeared broken.
        //
        // Removing it also drops the static `import DashboardLayout` that used to sit at the
        // top of this file. That single import pulled Menu, Navbar, InboxPopup and the emoji
        // picker into the eager entry chunk for every visitor, including the login page.
        resolvePageComponent(
            `./Pages/${name}.jsx`,
            import.meta.glob("./Pages/**/*.jsx"),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <ErrorBoundary>
                <App {...props} />
            </ErrorBoundary>,
        );
    },
    progress: {
        color: "#4B5563",
    },
});
