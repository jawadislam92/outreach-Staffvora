// Shared UI kit — one import surface for every page:
//   import { Button, Card, KpiCard, Table, Th, Td, Badge, Field, Input } from '@/Components/ui'
// Every component is theme-aware through the semantic tokens in resources/css/app.css.
export { default as Spinner } from './Spinner';
export { default as Button } from './Button';
export { default as Card, CardHeader } from './Card';
export { default as Badge } from './Badge';
export { default as StatCard, DeltaChip } from './StatCard';
export { default as KpiCard } from './KpiCard';
export { default as Table, THead, TBody, Tr, Th, Td } from './Table';
export { default as Field, Input, Select, Textarea, Checkbox, Toggle } from './Form';
export { default as EmptyState, DefaultInboxIcon } from './EmptyState';
export { default as Skeleton, SkeletonText, SkeletonCard } from './Skeleton';
export { default as Tabs, Segmented } from './Tabs';
export { default as Pagination } from './Pagination';
export { default as ConfirmDialog } from './ConfirmDialog';
