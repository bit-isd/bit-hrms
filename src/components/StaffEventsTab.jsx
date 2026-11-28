import React, { useEffect, useState } from "react";
import axios from "axios";
import { CalendarDays, Pencil, Plus, Trash2, X } from "lucide-react";
import { useDialog } from "./AppDialog";

const DEFAULT_DISCIPLINARY_ACTIONS = [
  "Dismissal", "Removal on grounds of inefficiency", "Discharge on grounds of general unsuitability for further employment",
  "Reduction in rank", "Reduction in salary", "Withholding of increment", "Withholding of salary", "Surcharge", "Reprimand",
  "Interdiction", "Suspension", "Deferment of increment", "Formal letter of advice / Written warning", "Fine",
  "Termination of appointment", "Retirement in public interest",
];
const DEFAULT_LEAVE_TYPES = ["Annual Leave", "Study Leave", "Terminal Leave", "Medical Leave", "Maternity Leave", "Paternity Leave", "Casual Leave", "Other"];
// An edited record keeps its existing value selectable even after the option is deactivated.
const choices = (list, current) => (current && !list.includes(current) ? [...list, current] : list).map((value) => [value, value]);

const TITLES = { PROMOTION: "Promotions", LEAVE: "Leave Management", DISCIPLINARY: "Disciplinary Actions", TRAINING: "Training & Professional Development" };
const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;
const MAX_UPLOAD_MESSAGE = "Files must not exceed 2 MB.";

export default function StaffEventsTab({ type, staff = null, apiBase = "/api/v1", canManage, canViewDiscipline, gradeLevels = [], options = {}, onOpenStaff }) {
  const leaveTypes = options.leave_type?.length ? options.leave_type : DEFAULT_LEAVE_TYPES;
  const disciplinaryActions = options.disciplinary_action?.length ? options.disciplinary_action : DEFAULT_DISCIPLINARY_ACTIONS;
  canManage = canManage && staff?.is_active !== false;
  const dialog = useDialog();
  const [events, setEvents] = useState([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState(null);
  const [error, setError] = useState("");
  const [staffSearch, setStaffSearch] = useState("");
  const [staffOptions, setStaffOptions] = useState([]);
  const [attachmentFile, setAttachmentFile] = useState(null);
  const [selectedStaffId, setSelectedStaffId] = useState(staff?.id || "");
  const blank = { title: "", occurred_on: new Date().toISOString().slice(0, 10), start_date: "", end_date: "", resumed_on: "", from_grade_level_id: "", to_grade_level_id: "", leave_type: leaveTypes.includes("Annual Leave") ? "Annual Leave" : leaveTypes[0], disciplinary_action: disciplinaryActions[0], reason: "", staff_response: "", action_taken: "", current_state: "", training_type: "", provider: "", qualification_obtained: "", circular_reference: "", remarks: "" };
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
    setEditing(null); setForm(blank); setAttachmentFile(null); setSelectedStaffId(staff?.id || ""); setError(""); setShowForm(true);
  };
  const startEdit = (event) => {
    setEditing(event); setForm(Object.fromEntries(Object.keys(blank).map((key) => [key, event[key] ?? ""]))); setAttachmentFile(null); setSelectedStaffId(event.staff_id); setError(""); setShowForm(true);
  };
  const save = async (event) => {
    event.preventDefault(); setError("");
    let payload = { ...form, type };
    if (type === "LEAVE") payload.occurred_on = form.start_date;
    if (type === "PROMOTION" && attachmentFile) {
      const multipart = new FormData();
      Object.entries(payload).forEach(([key, value]) => multipart.append(key, value ?? ""));
      multipart.append("file", attachmentFile);
      if (editing) multipart.append("_method", "PATCH");
      payload = multipart;
    }
    try {
      if (editing && payload instanceof FormData) await axios.post(`${apiBase}/staff-events/${editing.id}`, payload);
      else if (editing) await axios.patch(`${apiBase}/staff-events/${editing.id}`, payload);
      else await axios.post(`${apiBase}/staff/${selectedStaffId}/events`, payload);
      setAttachmentFile(null); setShowForm(false); await load();
    } catch (requestError) {
      setError(Object.values(requestError.response?.data?.errors || {}).flat().join(" ") || requestError.response?.data?.message || "Could not save this record.");
    }
  };
  const remove = async (event) => {
    if (!await dialog.confirm({ title: "Delete record", message: `Delete “${event.title}”?`, confirmText: "Delete", tone: "danger" })) return;
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
          {events.map((event) => <article key={event.id} className={`border border-slate-200 rounded p-3 flex gap-3 ${event.staff?.record_status === "retired" ? "staff-retired" : event.staff?.record_status === "suspended" ? "staff-inactive" : ""}`}>
            <div className="text-emerald-700 pt-1"><CalendarDays size={18} /></div>
            <div className="min-w-0 flex-1">
              {!staff && event.staff && <button type="button" onClick={() => onOpenStaff?.(event.staff)} className="text-xs text-emerald-800 font-semibold hover:underline">{fullName(event.staff)} · {event.staff.ogun_employee_number || event.staff.staff_number}</button>}
              {event.staff?.record_status === "retired" && <p className="text-xs font-bold text-rose-700">Retired — {event.staff.retirement_reason || "Statutory limit reached"}; read-only</p>}
              {event.staff?.record_status === "suspended" && <p className="text-xs font-bold text-rose-700">Suspended staff — read-only</p>}
              <h4 className="font-bold">{event.title}</h4>
              <p className="text-xs text-slate-500">{event.start_date || event.occurred_on}{event.end_date ? ` to ${event.end_date}` : ""}</p>
              {type === "PROMOTION" && <p className="text-xs mt-1">{event.from_grade_level?.name || "Previous grade"} → {event.to_grade_level?.name || "New grade"} {event.circular_reference && `· ${event.circular_reference}`}</p>}
              {type === "PROMOTION" && event.attachment_path && <a href={event.attachment_path} target="_blank" rel="noreferrer" className="inline-flex mt-1 text-xs font-semibold text-emerald-700 hover:underline">View promotion attachment{event.attachment_name ? ` · ${event.attachment_name}` : ""}</a>}
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
              <label className="block text-xs font-semibold text-slate-700 sm:col-span-2">Supporting file (optional)
                {editing?.attachment_path && <span className="block mt-1 font-normal text-slate-500">Current file: <a href={editing.attachment_path} target="_blank" rel="noreferrer" className="text-emerald-700 underline">{editing.attachment_name || "View attachment"}</a></span>}
                <input type="file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onChange={(event) => {
                  const file = event.target.files?.[0] || null;
                  if (file && file.size > MAX_UPLOAD_BYTES) {
                    event.target.value = "";
                    setAttachmentFile(null);
                    setError(MAX_UPLOAD_MESSAGE);
                    return;
                  }
                  setAttachmentFile(file);
                  setError("");
                }} className="mt-1 w-full px-2.5 py-1.5 border border-slate-300 rounded bg-white font-normal file:mr-3 file:py-1 file:px-2 file:rounded file:border-0 file:text-xs file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100" />
                <span className="block mt-1 font-normal text-slate-500">PDF, JPG, PNG, DOC or DOCX · Maximum 2 MB</span>
              </label>
            </>}
            {type === "LEAVE" && <>
              {select("Leave type", "leave_type", choices(leaveTypes, editing?.leave_type), true)}
              {input("Start date", "start_date", true, "date")}{input("End date", "end_date", true, "date")}{input("Official resumption date", "resumed_on", false, "date")}
            </>}
            {type === "DISCIPLINARY" && <>
              {select("Disciplinary action", "disciplinary_action", choices(disciplinaryActions, editing?.disciplinary_action), true)}
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