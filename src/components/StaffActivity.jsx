import React from "react";

export default function StaffActivity({ staff, canChange = false, onChange, busy = false }) {
  const active = staff.is_active !== false;
  return (
    <span className="inline-flex items-center gap-2 whitespace-nowrap">
      <span className={`text-xs font-bold ${active ? "text-emerald-700" : "text-rose-700"}`}>{active ? "Active" : "Inactive"}</span>
      {canChange && <button
        type="button"
        role="switch"
        aria-checked={active}
        aria-label={`${active ? "Deactivate" : "Reactivate"} ${staff.surname}, ${staff.first_name}`}
        disabled={busy}
        onClick={() => onChange(staff)}
        className={`no-print relative inline-flex h-5 w-9 shrink-0 items-center rounded-full disabled:opacity-50 ${active ? "bg-emerald-700" : "bg-rose-700"}`}
      ><span className={`inline-block h-3.5 w-3.5 rounded-full bg-white transition-transform ${active ? "translate-x-5" : "translate-x-0.5"}`} /></button>}
    </span>
  );
}
