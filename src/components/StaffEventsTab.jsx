import React, { useEffect, useState } from "react";
import axios from "axios";
import { CalendarDays, Pencil, Plus, Trash2, X } from "lucide-react";

const DISCIPLINARY_ACTIONS = [
  "Dismissal", "Removal on grounds of inefficiency", "Discharge on grounds of general unsuitability for further employment",
  "Reduction in rank", "Reduction in salary", "Withholding of increment", "Withholding of salary", "Surcharge", "Reprimand",
  "Interdiction", "Suspension", "Deferment of increment", "Formal letter of advice / Written warning", "Fine",
  "Termination of appointment", "Retirement in public interest",
];

const TITLES = { PROMOTION: "Promotions", LEAVE: "Leave Management", DISCIPLINARY: "Disciplinary Actions", TRAINING: "Training & Professional Development" };

export default function StaffEventsTab({ type, staff = null, apiBase = "/api/v1", canManage, canViewDiscipline, gradeLevels = [], onOpenStaff }) {
  canManage = canManage && staff?.is_active !== false;
  const [events, setEvents] = useState([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState(null);
  const [error, setError] = useState("");
  const [staffSearch, setStaffSearch] = useState("");
  const [staffOptions, setStaffOptions] = useState([]);
  const [selectedStaffId, setSelectedStaffId] = useState(staff?.id || "");
  const blank = { title: "", occurred_on: new Date().toISOString().slice(0, 10), start_date: "", end_date: "", resumed_on: "", from_grade_level_id: "", to_grade_level_id: "", leave_type: "Annual Leave", disciplinary_action: DISCIPLINARY_ACTIONS[0], reason: "", staff_response: "", action_taken: "", current_state: "", training_type: "", provider: "", qualification_obtained: "", circular_reference: "", remarks: "" };
  const [form, setForm] = useState(blank);

  const load = async () => {
    setLoading(true);
    try {
      const url = staff ? `${apiBase}/staff/${staff.id}/events` : `${apiBase}/staff-events`;
      const response = await axios.get(url, { params: { type, per_page: 100, page } });
      setEvents(staff ? response.data.data : response.data.data.data);
      setLastPage(staff ? 1 : response.data.data.last_page);
    } catch (requestError) {
      setError(requestError.response?.data?.message || "Could not load this staff history.");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, [staff?.id, type, page]);
  useEffect(() => {
    if (staff || !showForm || !staffSearch.trim()) { setStaffOptions([]); return; }
    const timer = setTimeout(async () => {
      try {
        const response = await axios.get(`${apiBase}/staff`, { params: { search: staffSearch, per_page: 10 } });
        setStaffOptions((response.data.data || []).filter((person) => person.is_active !== false));
      } catch (requestError) {
        setStaffOptions([]);
        setError(requestError.response?.data?.message || "Could not search staff records.");
      }
    }, 250);
    return () => clearTimeout(timer);
  }, [staffSearch, showForm, staff?.id]);

  const startCreate = () => {
    setEditing(null); setForm(blank); setSelectedStaffId(staff?.id || ""); setError(""); setShowForm(true);
  };
  const startEdit = (event) => {
    setEditing(event); setForm(Object.fromEntries(Object.keys(blank).map((key) => [key, event[key] ?? ""]))); setSelectedStaffId(event.staff_id); setError(""); setShowForm(true);
  };
  const save = async (event) => {
    event.preventDefault(); setError("");
    const payload = { ...form, type };
    if (type === "LEAVE") payload.occurred_on = form.start_date;
    try {
      if (editing) await axios.patch(`${apiBase}/staff-events/${editing.id}`, payload);
      else await axios.post(`${apiBase}/staff/${selectedStaffId}/events`, payload);
      setShowForm(false); await load();
    } catch (requestError) {
      setError(Object.values(requestError.response?.data?.errors || {}).flat().join(" ") || requestError.response?.data?.message || "Could not save this record.");
    }
  };
  const remove = async (event) => {
    if (!window.confirm(`Delete “${event.title}”?`)) return;
    try { await axios.delete(`${apiBase}/staff-events/${event.id}`); await load(); }
    catch (requestError) { setError(requestError.response?.data?.message || "Could not delete this record."); }
  };

  const input = (label, key, required = false, typeName = "text") => (
    <label className="block text-xs font-semibold text-slate-700">{label}{required ? " *" : ""}
      <input type={typeName} required={required} value={form[key] || ""} onChange={(event) => setForm({ ...form, [key]: event.target.value })} className="mt-1 w-full px-2.5 py-2 border border-slate-300 rounded bg-white font-normal" />
    </label>
  );
  const area = (label, key, required = false) => (
    <label className="block text-xs font-semibold text-slate-700">{label}{required ? " *" : ""}
      <textarea required={required} rows="2" value={form[key] || ""} onChange={(event) => setForm({ ...form, [key]: event.target.value })} className="mt-1 w-full px-2.5 py-2 border border-slate-300 rounded bg-white font-normal" />
    </label>
  );
  const select = (label, key, options, required = false) => (
    <label className="block text-xs font-semibold text-slate-700">{label}{required ? " *" : ""}
      <select required={required} value={form[key] || ""} onChange={(event) => setForm({ ...form, [key]: event.target.value })} className="mt-1 w-full px-2.5 py-2 border border-slate-300 rounded bg-white font-normal">
        <option value="">Select</option>{options.map(([value, name]) => <option key={value} value={value}>{name}</option>)}
      </select>
    </label>
  );
  const fullName = (person) => [person?.title, person?.surname, person?.first_name, person?.middle_name].filter(Boolean).join(" ");
  const countdown = (endDate) => Math.max(0, Math.ceil((new Date(`${endDate}T23:59:59`) - new Date()) / 86400000));

  if (type === "DISCIPLINARY" && !canViewDiscipline) return <p className="p-4 border rounded text-sm">Disciplinary records are restricted to HR administrators.</p>;

  return (
    <section className="space-y-4 text-sm">
      <header className="flex justify-between items-center gap-3">
        <div><h3 className="font-bold text-base">{staff ? `${TITLES[type]} History` : TITLES[type]}</h3><p className="text-xs text-slate-500">Chronological officer records</p></div>
        {canManage && <button type="button" onClick={startCreate} className="inline-flex items-center gap-1 px-3 py-2 bg-emerald-700 text-white rounded text-xs font-semibold"><Plus size={15} /> Add record</button>}
      </header>
      {error && <div role="alert" className="p-2 border border-rose-300 bg-rose-50 text-rose-800 rounded text-xs">{error}</div>}
      {loading ? <p className="py-8 text-center text-slate-500">Loading timeline...</p> : events.length === 0 ? <p className="py-8 text-center text-slate-500 border border-dashed rounded">No {TITLES[type].toLowerCase()} recorded.</p> : (
        <div className="space-y-3">
          {events.map((event) => <article key={event.id} className={`border border-slate-200 rounded p-3 flex gap-3 ${event.staff?.is_active === false ? "staff-inactive" : ""}`}>
            <div className="text-emerald-700 pt-1"><CalendarDays size={18} /></div>
            <div className="min-w-0 flex-1">
              {!staff && event.staff && <button type="button" onClick={() => onOpenStaff?.(event.staff)} className="text-xs text-emerald-800 font-semibold hover:underline">{fullName(event.staff)} · {event.staff.ogun_employee_number || event.staff.staff_number}</button>}
              {event.staff?.is_active === false && <p className="text-xs font-bold text-rose-700">Inactive staff — read-only</p>}
              <h4 className="font-bold">{event.title}</h4>
              <p className="text-xs text-slate-500">{event.start_date || event.occurred_on}{event.end_date ? ` to ${event.end_date}` : ""}</p>
              {type === "PROMOTION" && <p className="text-xs mt-1">{event.from_grade_level?.name || "Previous grade"} → {event.to_grade_level?.name || "New grade"} {event.circular_reference && `· ${event.circular_reference}`}</p>}
              {type === "LEAVE" && <div className="mt-1 text-xs"><span>{event.leave_type} · {event.leave_days} days</span>{event.resumed_on ? <span className="ml-2 text-emerald-700">Resumed {event.resumed_on}</span> : event.end_date && <span className="ml-2 font-semibold">{countdown(event.end_date)} days to scheduled end</span>}</div>}
              {type === "DISCIPLINARY" && <div className="mt-1 space-y-1 text-xs"><p><b>Action:</b> {event.disciplinary_action} · <b>State:</b> {event.current_state}</p><p><b>Reason:</b> {event.reason}</p><p><b>Officer response:</b> {event.staff_response || "Not recorded"}</p><p><b>Outcome:</b> {event.action_taken}</p></div>}
              {type === "TRAINING" && <p className="text-xs mt-1">{event.training_type}{event.provider ? ` · ${event.provider}` : ""}{event.qualification_obtained ? ` · Qualification: ${event.qualification_obtained}` : ""}</p>}
              {event.remarks && <p className="text-xs text-slate-600 mt-1">{event.remarks}</p>}
            </div>
            {canManage && event.staff?.is_active !== false && <div className="flex gap-1"><button type="button" title="Edit record" aria-label="Edit record" onClick={() => startEdit(event)} className="p-1.5 border rounded"><Pencil size={14} /></button><button type="button" title="Delete record" aria-label="Delete record" onClick={() => remove(event)} className="p-1.5 border rounded text-rose-700"><Trash2 size={14} /></button></div>}
          </article>)}
        </div>
      )}
      {!staff && lastPage > 1 && <div className="flex justify-end items-center gap-3 text-xs"><button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="px-3 py-1.5 border rounded disabled:opacity-40">Previous</button><span>Page {page} of {lastPage}</span><button type="button" disabled={page >= lastPage} onClick={() => setPage(page + 1)} className="px-3 py-1.5 border rounded disabled:opacity-40">Next</button></div>}

      {showForm && canManage && <div className="fixed inset-0 z-[70] bg-slate-950/60 flex items-center justify-center p-3 overflow-y-auto">
        <form onSubmit={save} className="bg-white w-full max-w-3xl max-h-[92vh] overflow-y-auto p-5 rounded border border-slate-300 space-y-4">
          <header className="flex justify-between"><h3 className="font-bold">{editing ? "Update" : "Add"} {TITLES[type].replace(/s$/, "")} Record</h3><button type="button" aria-label="Close" onClick={() => setShowForm(false)}><X size={18} /></button></header>
          {error && <p role="alert" className="text-rose-700 text-xs">{error}</p>}
          {!staff && <div className="relative"><label className="text-xs font-semibold">Find officer *<input required={!selectedStaffId} value={staffSearch} onChange={(event) => { setStaffSearch(event.target.value); setSelectedStaffId(""); }} className="mt-1 w-full px-2.5 py-2 border rounded" placeholder="Name, staff ID or OG number" /></label>{staffOptions.length > 0 && <div className="absolute z-10 bg-white border shadow w-full max-h-40 overflow-auto">{staffOptions.map((person) => <button type="button" key={person.id} onClick={() => { setSelectedStaffId(person.id); setStaffSearch(`${fullName(person)} · ${person.ogun_employee_number || person.staff_number}`); setStaffOptions([]); }} className="block w-full text-left px-3 py-2 hover:bg-emerald-50 text-xs">{fullName(person)} · {person.ogun_employee_number || person.staff_number}</button>)}</div>}</div>}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {input("Record title", "title", true)}
            {type !== "LEAVE" && type !== "TRAINING" && input("Date", "occurred_on", true, "date")}
            {type === "PROMOTION" && <>
              {select("Current grade", "from_grade_level_id", gradeLevels.map((grade) => [grade.id, grade.name]), true)}
              {select("Promoted grade", "to_grade_level_id", gradeLevels.map((grade) => [grade.id, grade.name]), true)}
              {input("Promotion circular / reference", "circular_reference")}
            </>}
            {type === "LEAVE" && <>
              {select("Leave type", "leave_type", ["Annual Leave", "Study Leave", "Terminal Leave", "Medical Leave", "Maternity Leave", "Paternity Leave", "Casual Leave", "Other"].map((value) => [value, value]), true)}
              {input("Start date", "start_date", true, "date")}{input("End date", "end_date", true, "date")}{input("Official resumption date", "resumed_on", false, "date")}
            </>}
            {type === "DISCIPLINARY" && <>
              {select("Disciplinary action", "disciplinary_action", DISCIPLINARY_ACTIONS.map((value) => [value, value]), true)}
              {input("Current employment state", "current_state", true)}{area("Reason / allegation", "reason", true)}{area("Officer response", "staff_response")}{area("Outcome / action taken", "action_taken", true)}
            </>}
            {type === "TRAINING" && <>
              {input("Training / programme type", "training_type", true)}{input("Provider / institution", "provider")}
              {input("Start date", "start_date", true, "date")}{input("End date", "end_date", true, "date")}
              {input("Qualification obtained", "qualification_obtained")}
            </>}
            {area("Additional notes", "remarks")}
          </div>
          <footer className="flex justify-end gap-2 border-t pt-3"><button type="button" onClick={() => setShowForm(false)} className="px-3 py-2 border rounded text-xs">Cancel</button><button className="px-3 py-2 bg-emerald-700 text-white rounded text-xs font-semibold">Save record</button></footer>
        </form>
      </div>}
    </section>
  );
}