import React from "react";

/**
 * Top-level error boundary.
 *
 * A repo-wide grep for componentDidCatch/getDerivedStateFromError previously returned
 * nothing, so any render-time throw — a null field in an invoice, a missing relation,
 * a date that failed to parse — white-screened the entire application with no message
 * and no way back other than a manual reload.
 *
 * Must be a class component: React has no hook equivalent for error boundaries.
 */
export default class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { error: null };
    }

    static getDerivedStateFromError(error) {
        return { error };
    }

    componentDidCatch(error, errorInfo) {
        // Keep this: it is the only signal that a client-side crash happened at all.
        console.error("Unhandled render error:", error, errorInfo);
    }

    handleReload = () => {
        this.setState({ error: null });
        window.location.reload();
    };

    render() {
        if (!this.state.error) {
            return this.props.children;
        }

        const isDev = process.env.NODE_ENV === "development";

        return (
            <div className="flex min-h-screen items-center justify-center bg-[#F7F8FA] p-6">
                <div className="w-full max-w-lg rounded-lg bg-white p-8 shadow">
                    <h1 className="mb-2 text-xl font-semibold text-gray-900">
                        Une erreur est survenue
                    </h1>
                    <p className="mb-6 text-sm text-gray-600">
                        La page n'a pas pu s'afficher. Vos données n'ont pas été
                        modifiées. Rechargez la page pour réessayer.
                    </p>

                    {isDev && (
                        <pre className="mb-6 max-h-48 overflow-auto rounded bg-gray-100 p-3 text-xs text-red-700">
                            {String(this.state.error?.stack || this.state.error)}
                        </pre>
                    )}

                    <div className="flex gap-3">
                        <button
                            onClick={this.handleReload}
                            className="rounded-md bg-lamaPurple px-4 py-2 text-sm font-medium text-white hover:opacity-90"
                        >
                            Recharger
                        </button>
                        <a
                            href="/dashboard"
                            className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                        >
                            Retour au tableau de bord
                        </a>
                    </div>
                </div>
            </div>
        );
    }
}
