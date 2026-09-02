import React from 'react';
import { AlertTriangle } from 'lucide-react';

export default class ErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = { hasError: false, error: null };
    }

    static getDerivedStateFromError(error) {
        return { hasError: true, error };
    }

    componentDidCatch(error, info) {
        console.error('Unhandled render error:', error, info);
    }

    render() {
        if (!this.state.hasError) return this.props.children;

        return (
            <div className="min-h-screen flex items-center justify-center bg-app px-4">
                <div className="w-full max-w-md rounded-2xl border border-line bg-surface p-8 shadow-sm text-center">
                    <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-danger-soft text-danger">
                        <AlertTriangle className="h-7 w-7" />
                    </div>
                    <h1 className="mt-4 text-xl font-semibold text-body">Something went wrong</h1>
                    <p className="mt-2 text-sm text-muted">
                        The page hit an unexpected error. Reloading usually fixes it; nothing you sent was lost.
                    </p>
                    {import.meta.env.DEV && this.state.error && (
                        <pre className="mt-4 max-h-40 overflow-auto rounded-lg bg-surface-2 p-3 text-left text-xs text-danger">
                            {String(this.state.error)}
                        </pre>
                    )}
                    <div className="mt-6 flex gap-3">
                        <button
                            type="button"
                            onClick={() => window.location.reload()}
                            className="flex-1 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:brightness-110"
                        >
                            Reload
                        </button>
                        <a
                            href="/dashboard"
                            className="flex-1 rounded-lg border border-line bg-surface-2 px-4 py-2 text-sm font-semibold text-body hover:bg-surface"
                        >
                            Dashboard
                        </a>
                    </div>
                </div>
            </div>
        );
    }
}
