import { useEffect, useRef } from "react";
import { router } from "@inertiajs/react";

/*
 * The single navigation path for a filtered index page.
 *
 * Every list page in this app had grown the same two bugs, independently:
 *
 *   1. A `useEffect(..., [filters])` that calls `router.get`. React runs an effect on MOUNT,
 *      not only on change, so opening the page immediately re-requested the page the server
 *      had just delivered — visible as /classes rewriting itself to
 *      /classes?level=&school=&search= a moment after load. Two full round trips per view,
 *      a junk history entry, and a URL full of empty parameters.
 *
 *   2. A `handleFilterChange` that ALSO called `router.get` directly, on top of the
 *      `setFilters` that schedules the effect. So each filter change fired two requests for
 *      the same rows. The second won, which is why nobody noticed outside the network tab.
 *
 * The guard that fixes both is "does the filter state differ from what the server actually
 * rendered with?". `serverFilters` is the server's echo of the query string, so equality
 * means the rows on screen are already the answer to these filters and there is nothing to
 * request. Some controllers echo `search` inside their `filters` prop and some as its own
 * prop, so each page assembles `serverFilters` itself.
 *
 * Callers must NOT navigate on their own — set filter state and let this hook do it.
 */

const normalise = (value) => String(value ?? "").trim();

/** Values meaning "no filter". Controllers treat a missing param and 'all' identically. */
const isUnset = (value) => normalise(value) === "" || normalise(value) === "all";

export default function useFilterNavigation({
    routeName,
    filters,
    serverFilters,
    paused = false,
    delay = 300,
}) {
    const isFirstRun = useRef(true);

    useEffect(() => {
        if (isFirstRun.current) {
            isFirstRun.current = false;
            return;
        }

        // Set while a page-restore navigation is in flight, so its own setFilters() does not
        // bounce us back to page 1.
        if (paused) {
            return;
        }

        const matchesServer = Object.keys(serverFilters).every(
            (key) => normalise(filters[key]) === normalise(serverFilters[key]),
        );

        if (matchesServer) {
            return;
        }

        const timeoutId = setTimeout(() => {
            // Send only the filters actually set. Sending every key produced
            // ?class=&page=1&school=&search=&status= — parameters that mean exactly the same
            // as omitting them, and that made a cleared filter set look like an applied one
            // in the URL and in history. Omitting `page` lets the server apply its default
            // of 1, which is what filter changes want anyway.
            const params = Object.fromEntries(
                Object.entries(filters)
                    .map(([key, value]) => [key, normalise(value)])
                    .filter(([, value]) => !isUnset(value)),
            );

            router.get(route(routeName), params, {
                preserveState: true,
                replace: true,
                preserveScroll: true,
            });
        }, delay);

        return () => clearTimeout(timeoutId);
    }, [filters, paused]);
}
