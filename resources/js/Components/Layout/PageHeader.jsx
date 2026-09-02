export default function PageHeader({ eyebrow, title, description, actions = null }) {
    return (
        <section className="flex flex-col gap-3 pb-1 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
                {eyebrow && <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-accent">{eyebrow}</p>}
                <h1 className="text-[26px] font-bold leading-tight tracking-tight text-body">{title}</h1>
                {description && <p className="mt-1 text-sm text-muted">{description}</p>}
            </div>
            {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
        </section>
    );
}
