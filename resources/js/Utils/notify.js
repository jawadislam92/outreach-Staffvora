import React from 'react';
import toast from 'react-hot-toast';
import { CheckCircle, AlertCircle, XCircle, Info } from 'lucide-react';

const baseStyle = {
    background: 'var(--surface)',
    color: 'var(--text)',
    border: '1px solid var(--border)',
    borderLeftWidth: '4px',
    borderRadius: '12px',
    padding: '12px 14px',
    fontSize: '14px',
    fontWeight: 500,
    boxShadow: '0 12px 30px -12px rgba(0,0,0,0.35)',
    maxWidth: '420px',
};

const make = (Icon, colorVar, duration) => (message, opts = {}) =>
    toast(message, {
        duration,
        ...opts,
        style: { ...baseStyle, borderLeftColor: `var(${colorVar})`, ...(opts.style || {}) },
        icon: React.createElement(Icon, { style: { width: 18, height: 18, color: `var(${colorVar})`, flexShrink: 0 } }),
    });

export const notify = {
    success: make(CheckCircle, '--success', 3500),
    error: make(XCircle, '--danger', 6000),
    warning: make(AlertCircle, '--warning', 5000),
    info: make(Info, '--info', 4000),
    dismiss: (id) => toast.dismiss(id),
};

export default notify;
