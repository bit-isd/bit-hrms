import React from "react";

export default function StaffActivity({ staff, canChange = false, onChange, busy = false }) {
  const status = staff.record_status || (staff.is_active === false ? "suspended" : "active");
  const active = status === "active";
  const canToggle = canChange && status !== "retired";
  return (
    <span className="inline-flex max-w-full flex-wrap items-center gap-2">
      <span className={`min-w-0 break-words text-xs font-bold leading-tight ${status === "active" ? "text-emerald-700" : "text-rose-700"}`}>
        {status === "active" ? "Active" : status === "retired" ? `Retired${staff.retirement_reason ? ` — ${staff.retirement_reason}` : ""}` : "Suspended"}
      </span>
      {canToggle && <button
        type="button"
        role="switch"
        aria-checked={active}
        aria-label={`${active ? "Suspend" : "Activate"} ${staff.surname}, ${staff.first_name}`}
        disabled={busy}
        onClick={() => onChange(staff)}
        className={`no-print relative inline-flex h-5 w-9 shrink-0 items-center rounded-full disabled:opacity-50 ${active ? "bg-emerald-700" : "bg-rose-700"}`}
      ><span className={`inline-block h-3.5 w-3.5 rounded-full bg-white transition-transform ${active ? "translate-x-5" : "translate-x-0.5"}`} /></button>}
    </span>
  );
}
