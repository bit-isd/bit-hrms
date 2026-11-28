import React, { useEffect, useState } from "react";
import { MapPin } from "lucide-react";
import StaffActivity from "./StaffActivity";

const VIEW_KEY = "bit_hrms_table_view";

export function useTableView() {
  const [view, setView] = useState(() => (localStorage.getItem(VIEW_KEY) === "compact" ? "compact" : "regular"));
  useEffect(() => { localStorage.setItem(VIEW_KEY, view); }, [view]);
  return [view, setView];
}

export function TableViewToggle({ view, onChange }) {
  const compact = view === "compact";
  return (
    <span className="no-print inline-flex items-center gap-2 rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-semibold text-slate-700">
      <span className={compact ? "text-slate-400" : "text-emerald-800"}>Regular</span>
      <button
        type="button"
        role="switch"
        aria-checked={compact}
        aria-label="Compact table view"
        title={compact ? "Switch to regular table view" : "Switch to compact table view"}
        onClick={() => onChange(compact ? "regular" : "compact")}
        className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors ${compact ? "bg-emerald-700" : "bg-slate-400"}`}
      ><span className={`inline-block h-3.5 w-3.5 rounded-full bg-white transition-transform ${compact ? "translate-x-5" : "translate-x-0.5"}`} /></button>
      <span className={compact ? "text-emerald-800" : "text-slate-400"}>Compact</span>
    </span>
  );
}

export function StationLabel({ staff }) {
  if (staff.current_station && staff.current_station.includes("Posted")) {
    return (
      <span className="inline-flex max-w-full items-center px-1.5 py-0.5 rounded text-[11px] font-bold bg-amber-100 text-amber-900 [overflow-wrap:anywhere]">
        <MapPin size={12} className="inline-block mr-1 shrink-0" /> {staff.current_station}
      </span>
    );
  }
  return <span className="text-slate-700 font-medium">{staff.current_station || staff.remarks || "BIT HQ, Ogun Tech Hub, Kobape"}</span>;
}

function DateLines({ items }) {
  return (
    <dl className="grid grid-cols-[auto_1fr] gap-x-2 gap-y-0.5 font-mono">
      {items.map(([label, value, highlight]) => (
        <React.Fragment key={label}>
          <dt className={`font-sans text-[11px] font-bold uppercase ${highlight ? "text-amber-800" : "text-slate-400"}`}>{label}</dt>
          <dd className={`whitespace-nowrap text-[13px] ${highlight ? "font-bold text-amber-800" : "text-slate-700"}`}>{value || "—"}</dd>
        </React.Fragment>
      ))}
    </dl>
  );
}

// Compact layout: related fields are stacked so every column fits on screen without horizontal scrolling.
export function CompactStaffTable({ rows, variant = "roll", startIndex = 0, loading, emptyMessage, onOpen, onEdit, canEdit = false, canChangeActivity = false, onActivityChange, activityBusy }) {
  const roll = variant === "roll";
  const dates = (staff) => roll
    ? [["DOB", staff.date_of_birth], ["1st Appt", staff.date_of_first_appointment], ["Conf", staff.date_of_confirmation], ["Pres", staff.date_of_present_appointment], ["Ret", staff.expected_retirement_date, true]]
    : [["DOB", staff.date_of_birth], ["1st Appt", staff.date_of_first_appointment], ["Pres", staff.date_of_present_appointment]];
  const colSpan = roll ? 7 : 6;

  return (
    <div className="compact-staff-table bg-white rounded-lg border border-slate-200 shadow-xs overflow-x-auto">
      <table className="w-full min-w-[820px] table-fixed text-left border-collapse">
        <colgroup>
          <col className="w-12" />
          <col className={roll ? "w-[18%]" : "w-[22%]"} />
          <col className={roll ? "w-[17%]" : "w-[24%]"} />
          <col className="w-[19%]" />
          {roll && <col className="w-[12%]" />}
          <col />
          <col className="w-28" />
        </colgroup>
        <thead>
          <tr className="bg-slate-100 text-slate-800 text-xs font-bold uppercase border-b border-slate-200">
            <th className="py-3 px-2 text-center">S/N</th>
            <th className="py-3 px-3">Officer / OG No.</th>
            <th className="py-3 px-3">Designation / Cadre / GL</th>
            <th className="py-3 px-3">Key Dates</th>
            {roll && <th className="py-3 px-3">LGA / Qualification</th>}
            <th className="py-3 px-3">Current Station / Remarks</th>
            <th className="py-3 px-3">Status</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100 text-sm">
          {loading ? (
            <tr><td colSpan={colSpan} className="py-8 text-center text-slate-400">Loading staff records...</td></tr>
          ) : rows.length === 0 ? (
            <tr><td colSpan={colSpan} className="py-8 text-center text-slate-400">{emptyMessage}</td></tr>
          ) : rows.map((staff, index) => (
            <tr key={staff.id} className={`align-top ${staff.record_status === "retired" ? "staff-retired" : staff.confirmation_due ? "staff-confirmation-due" : staff.record_status === "suspended" || staff.is_active === false ? "staff-inactive" : "hover:bg-emerald-50/40 transition-colors"}`}>
              <td className="py-3 px-2 font-mono text-center text-slate-500 font-bold">{startIndex + index + 1}</td>
              <td className="py-3 px-3 break-words">
                <button type="button" className="print-text text-left hover:underline" onClick={() => onOpen(staff)}>
                  <span className="font-black text-slate-950 uppercase">{staff.surname}</span>, {staff.title ? `${staff.title} ` : ""}{staff.first_name} {staff.middle_name || ""}
                </button>
                <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[13px]">
                  <button type="button" className="print-text font-mono font-bold text-emerald-700 hover:underline" onClick={() => onOpen(staff)}>{staff.ogun_employee_number || staff.staff_number}</button>
                  <span className="text-slate-500">· {staff.gender === "Female" ? "F" : "M"}</span>
                </div>
              </td>
              <td className="py-3 px-3 break-words">
                <div className="font-semibold text-slate-900">{staff.designation?.name || "—"}</div>
                {staff.designation?.cadre_name && <div className="text-[13px] text-emerald-800">{staff.designation.cadre_name}</div>}
                <div className="mt-0.5 text-[13px] font-bold text-slate-600">{staff.grade_level?.name || "—"}{staff.step ? ` / Step ${staff.step}` : ""}</div>
              </td>
              <td className="py-3 px-3 text-[13px]"><DateLines items={dates(staff)} /></td>
              {roll && (
                <td className="py-3 px-3 break-words">
                  <div className="text-slate-700">{staff.lga_of_origin || "—"}</div>
                  <div className="text-[13px] font-medium text-slate-500">{staff.qualification || "—"}</div>
                </td>
              )}
              <td className="py-3 px-3 break-words"><StationLabel staff={staff} /></td>
              <td className="py-3 px-3">
                <StaffActivity staff={staff} canChange={canChangeActivity} onChange={onActivityChange} busy={activityBusy === staff.id} />
                <div className="no-print mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[13px] font-bold">
                  <button type="button" onClick={() => onOpen(staff)} className="text-emerald-700 hover:text-emerald-900">Profile</button>
                  {canEdit && staff.record_status === "active" && onEdit && <button type="button" onClick={() => onEdit(staff)} className="text-blue-700 hover:text-blue-900">Edit</button>}
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
