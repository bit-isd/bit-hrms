import React, { useEffect, useMemo, useState } from "react";
import axios from "axios";
import { Pencil, Plus, Power, RefreshCw, Settings2, Trash2, X } from "lucide-react";
import { useDialog } from "./AppDialog";

const SECTIONS = [
  { key: "departments", label: "Departments", singular: "Department" },
  { key: "units", label: "Units", singular: "Unit" },
  { key: "designations", label: "Designations", singular: "Designation" },
  { key: "grade_levels", label: "Grade Levels", singular: "Grade level" },
  { key: "employment_statuses", label: "Posting / Employment Statuses", singular: "Status" },
  { key: "lga", label: "LGAs of Origin", singular: "LGA", option: true },
  { key: "title", label: "Staff Titles", singular: "Title", option: true },
  { key: "leave_type", label: "Leave Types", singular: "Leave type", option: true },
  { key: "disciplinary_action", label: "Disciplinary Actions", singular: "Disciplinary action", option: true },
];

const FIELDS = {
  departments: [
    { key: "name", label: "Department name", required: true },
    { key: "code", label: "Code", required: true, code: true },
    { key: "description", label: "Description", textarea: true },
  ],
  units: [
    { key: "department_id", label: "Department", required: true, departmentSelect: true },
    { key: "name", label: "Unit name", required: true },
    { key: "code", label: "Code", required: true, code: true },
  ],
  designations: [
    { key: "name", label: "Designation", required: true },
    { key: "code", label: "Code", required: true, code: true },
    { key: "cadre_name", label: "Cadre" },
  ],
  grade_levels: [
    { key: "name", label: "Grade level name (e.g. GL 08)", required: true },
    { key: "level_number", label: "Level number", required: true, type: "number", min: 1, max: 99 },
    { key: "min_step", label: "Minimum step", type: "number", min: 1, max: 15 },
    { key: "max_step", label: "Maximum step", type: "number", min: 1, max: 15 },
    { key: "description", label: "Description", textarea: true },
  ],
  employment_statuses: [
    { key: "name", label: "Status name", required: true },
    { key: "code", label: "Code", required: true, code: true },
    { key: "is_active_nominal", label: "Count staff with this status on the nominal roll", checkbox: true },
  ],
  option: [
    { key: "value", label: "Value", required: true },
    { key: "sort_order", label: "Display order", type: "number", min: 0, max: 9999 },
  ],
};

const DEFAULTS = {
  grade_levels: { min_step: 1, max_step: 15 },
  employment_statuses: { is_active_nominal: true },
};

const errorText = (error, fallback) => Object.values(error.response?.data?.errors || {}).flat().join(" ") || error.response?.data?.message || fallback;

export default function SystemConfiguration({ apiBase = "/api/v1", onChanged }) {
  const dialog = useDialog();
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [section, setSection] = useState("departments");
  const [search, setSearch] = useState("");
  const [form, setForm] = useState(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState(null);

  const current = SECTIONS.find((item) => item.key === section);
  const fields = current.option ? FIELDS.option : FIELDS[section];
  const reservedCodes = data?.reserved_status_codes || [];
  const records = useMemo(() => {
    const list = (current.option ? data?.options?.[section] : data?.[section]) || [];
    const term = search.trim().toLowerCase();
    if (!term) return list;
    return list.filter((record) => [record.name, record.code, record.value, record.cadre_name, record.department?.name]
      .some((value) => String(value ?? "").toLowerCase().includes(term)));
  }, [data, section, search, current.option]);

  const counts = useMemo(() => Object.fromEntries(SECTIONS.map((item) => {
    const list = (item.option ? data?.options?.[item.key] : data?.[item.key]) || [];
    return [item.key, list.length];
  })), [data]);

  const applyResponse = (response) => {
    setData(response.data.data);
    onChanged?.();
  };

  const load = async () => {
    setLoading(true);
    try {
      const response = await axios.get(`${apiBase}/admin/configuration`);
      setData(response.data.data);
    } catch (error) {
      setNotice({ type: "error", message: errorText(error, "System configuration could not be loaded.") });
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => { load(); }, []);
  useEffect(() => { setSearch(""); setForm(null); }, [section]);

  const isReserved = (record) => section === "employment_statuses" && reservedCodes.includes(record.code);
  const codeLocked = (record) => Boolean(record && (record.usage_count > 0 || isReserved(record)));

  const openCreate = () => {
    setNotice(null);
    setForm({ record: null, values: { ...(DEFAULTS[section] || {}) }, error: "" });
  };
  const openEdit = (record) => {
    setNotice(null);
    setForm({ record, values: Object.fromEntries(fields.map((field) => [field.key, record[field.key] ?? (field.checkbox ? false : "")])), error: "" });
  };
  const setValue = (key, value) => setForm((previous) => ({ ...previous, values: { ...previous.values, [key]: value }, error: "" }));

  const save = async (event) => {
    event.preventDefault();
    setBusy(true);
    const numeric = new Set(fields.filter((field) => field.type === "number").map((field) => field.key));
    const payload = Object.fromEntries(Object.entries(form.values)
      .filter(([key, value]) => !(numeric.has(key) && value === ""))
      .map(([key, value]) => [key, value === "" ? null : value]));
    try {
      const response = form.record
        ? await axios.patch(`${apiBase}/admin/configuration/${section}/${form.record.id}`, payload)
        : await axios.post(`${apiBase}/admin/configuration/${section}`, payload);
      applyResponse(response);
      setNotice({ type: "success", message: response.data.message });
      setForm(null);
    } catch (error) {
      setForm((previous) => ({ ...previous, error: errorText(error, "The change could not be saved.") }));
    } finally {
      setBusy(false);
    }
  };

  const label = (record) => record.value ?? record.name;

  const toggleActive = async (record) => {
    const activate = !record.is_active;
    const confirmed = await dialog.confirm({
      title: `${activate ? "Re-activate" : "Deactivate"} ${current.singular.toLowerCase()}`,
      message: activate
        ? `"${label(record)}" will be available again for new entries.`
        : `"${label(record)}" will be hidden from new entries. Existing records that use it are not changed.`,
      confirmText: activate ? "Re-activate" : "Deactivate",
      tone: activate ? "default" : "danger",
    });
    if (!confirmed) return;
    setBusy(true);
    try {
      const response = await axios.patch(`${apiBase}/admin/configuration/${section}/${record.id}`, { is_active: activate });
      applyResponse(response);
      setNotice({ type: "success", message: `"${label(record)}" ${activate ? "re-activated" : "deactivated"}.` });
    } catch (error) {
      setNotice({ type: "error", message: errorText(error, "The status could not be changed.") });
    } finally {
      setBusy(false);
    }
  };

  const remove = async (record) => {
    const confirmed = await dialog.confirm({
      title: `Remove ${current.singular.toLowerCase()}`,
      message: `Permanently remove "${label(record)}"? This cannot be undone.`,
      confirmText: "Remove",
      tone: "danger",
    });
    if (!confirmed) return;
    setBusy(true);
    try {
      const response = await axios.delete(`${apiBase}/admin/configuration/${section}/${record.id}`);
      applyResponse(response);
      setNotice({ type: "success", message: response.data.message });
    } catch (error) {
      setNotice({ type: "error", message: errorText(error, "The value could not be removed.") });
    } finally {
      setBusy(false);
    }
  };

  const columns = {
    departments: [["Code", (r) => r.code], ["Units", (r) => r.units_count ?? 0]],
    units: [["Code", (r) => r.code], ["Department", (r) => r.department?.name || "—"]],
    designations: [["Code", (r) => r.code], ["Cadre", (r) => r.cadre_name || "—"]],
    grade_levels: [["Level", (r) => r.level_number], ["Steps", (r) => `${r.min_step}–${r.max_step}`]],
    employment_statuses: [["Code", (r) => r.code], ["Nominal roll", (r) => (r.is_active_nominal ? "Counted" : "Excluded")]],
  }[section] || [["Order", (r) => r.sort_order]];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 rounded-lg border border-slate-200 bg-white p-5 shadow-xs sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-start gap-3">
          <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-purple-100 text-purple-800"><Settings2 size={20} /></span>
          <div>
            <h2 className="text-lg font-bold text-slate-900">System Configuration</h2>
            <p className="text-xs text-slate-500">Manage the option lists used across BIT-HRMS. Values in use cannot be removed — deactivate them to hide them from new entries while keeping existing records intact.</p>
          </div>
        </div>
        <button type="button" onClick={load} disabled={loading} className="inline-flex items-center justify-center gap-1.5 rounded border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50">
          <RefreshCw size={14} className={loading ? "animate-spin" : ""} /> Refresh
        </button>
      </div>

      {notice && (
        <div role={notice.type === "error" ? "alert" : "status"} className={`flex items-start justify-between gap-3 rounded border px-4 py-3 text-xs ${notice.type === "error" ? "border-rose-200 bg-rose-50 text-rose-800" : "border-emerald-200 bg-emerald-50 text-emerald-800"}`}>
          <span>{notice.message}</span>
          <button type="button" onClick={() => setNotice(null)} aria-label="Dismiss message"><X size={14} /></button>
        </div>
      )}

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[16rem_1fr]">
        <nav aria-label="Configuration lists" className="flex gap-1 overflow-x-auto rounded-lg border border-slate-200 bg-white p-2 shadow-xs lg:flex-col lg:overflow-visible">
          {SECTIONS.map((item) => (
            <button
              key={item.key}
              type="button"
              onClick={() => setSection(item.key)}
              aria-current={section === item.key ? "page" : undefined}
              className={`flex shrink-0 items-center justify-between gap-3 rounded px-3 py-2 text-left text-xs font-semibold transition-colors ${section === item.key ? "bg-emerald-700 text-white" : "text-slate-700 hover:bg-slate-100"}`}
            >
              <span>{item.label}</span>
              <span className={`rounded-full px-2 py-0.5 text-[10px] ${section === item.key ? "bg-emerald-900/40 text-white" : "bg-slate-100 text-slate-600"}`}>{counts[item.key] ?? 0}</span>
            </button>
          ))}
        </nav>

        <section className="min-w-0 rounded-lg border border-slate-200 bg-white shadow-xs">
          <div className="flex flex-col gap-3 border-b border-slate-200 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h3 className="text-sm font-bold text-slate-900">{current.label}</h3>
              <p className="text-[11px] text-slate-500">"In use" counts staff records, history entries and user accounts that reference each value.</p>
            </div>
            <div className="flex gap-2">
              <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder={`Search ${current.label.toLowerCase()}`} aria-label={`Search ${current.label}`} className="w-full rounded border border-slate-300 px-3 py-2 text-xs sm:w-56" />
              <button type="button" onClick={openCreate} disabled={busy || loading} className="inline-flex shrink-0 items-center gap-1.5 rounded bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">
                <Plus size={14} /> Add {current.singular.toLowerCase()}
              </button>
            </div>
          </div>

          {form && (
            <form onSubmit={save} className="space-y-3 border-b border-slate-200 bg-slate-50 p-4">
              <div className="flex items-center justify-between">
                <h4 className="text-xs font-bold uppercase tracking-wide text-slate-600">{form.record ? `Edit ${current.singular.toLowerCase()}` : `New ${current.singular.toLowerCase()}`}</h4>
                <button type="button" onClick={() => setForm(null)} aria-label="Close form" className="text-slate-500 hover:text-slate-800"><X size={16} /></button>
              </div>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                {fields.map((field) => {
                  const locked = (field.code && codeLocked(form.record))
                    || (field.key === "value" && form.record?.usage_count > 0)
                    || (field.departmentSelect && form.record?.usage_count > 0);
                  const value = form.values[field.key] ?? "";
                  const common = { id: `config-${field.key}`, disabled: locked, required: field.required && !locked };
                  if (field.checkbox) {
                    return (
                      <label key={field.key} className="flex items-center gap-2 text-xs font-semibold text-slate-700 sm:col-span-2">
                        <input type="checkbox" checked={Boolean(form.values[field.key])} onChange={(event) => setValue(field.key, event.target.checked)} className="h-4 w-4 rounded border-slate-300 text-emerald-700" />
                        {field.label}
                      </label>
                    );
                  }
                  return (
                    <label key={field.key} htmlFor={common.id} className={`block text-xs font-semibold text-slate-700 ${field.textarea ? "sm:col-span-2" : ""}`}>
                      {field.label}{field.required ? " *" : ""}
                      {field.departmentSelect ? (
                        <select {...common} value={value} onChange={(event) => setValue(field.key, event.target.value)} className="mt-1 w-full rounded border border-slate-300 bg-white px-2.5 py-2 font-normal disabled:bg-slate-100">
                          <option value="">Select department</option>
                          {(data?.departments || []).map((department) => <option key={department.id} value={department.id}>{department.name}{department.is_active ? "" : " (inactive)"}</option>)}
                        </select>
                      ) : field.textarea ? (
                        <textarea {...common} rows="2" value={value} onChange={(event) => setValue(field.key, event.target.value)} className="mt-1 w-full rounded border border-slate-300 bg-white px-2.5 py-2 font-normal" />
                      ) : (
                        <input {...common} type={field.type || "text"} min={field.min} max={field.max} value={value} onChange={(event) => setValue(field.key, field.code ? event.target.value.toUpperCase() : event.target.value)} className={`mt-1 w-full rounded border border-slate-300 bg-white px-2.5 py-2 font-normal disabled:bg-slate-100 ${field.code ? "font-mono uppercase" : ""}`} />
                      )}
                      {locked && <span className="mt-1 block font-normal text-amber-700">Locked because this value is in use{isReserved(form.record) ? " or required by the system" : ""}.</span>}
                    </label>
                  );
                })}
              </div>
              {form.error && <p role="alert" className="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">{form.error}</p>}
              <div className="flex justify-end gap-2">
                <button type="button" onClick={() => setForm(null)} className="rounded border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-white">Cancel</button>
                <button type="submit" disabled={busy} className="rounded bg-emerald-700 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">{busy ? "Saving…" : "Save"}</button>
              </div>
            </form>
          )}

          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50 text-[10px] uppercase tracking-wider text-slate-500">
                <tr>
                  <th scope="col" className="px-4 py-2.5 font-bold">{current.option ? "Value" : "Name"}</th>
                  {columns.map(([heading]) => <th key={heading} scope="col" className="px-4 py-2.5 font-bold">{heading}</th>)}
                  <th scope="col" className="px-4 py-2.5 font-bold">In use</th>
                  <th scope="col" className="px-4 py-2.5 font-bold">Status</th>
                  <th scope="col" className="px-4 py-2.5 text-right font-bold">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {loading && <tr><td colSpan={columns.length + 4} className="px-4 py-8 text-center text-slate-500">Loading configuration…</td></tr>}
                {!loading && records.length === 0 && <tr><td colSpan={columns.length + 4} className="px-4 py-8 text-center text-slate-500">{search ? "No values match your search." : "No values have been added yet."}</td></tr>}
                {!loading && records.map((record) => {
                  const reserved = isReserved(record);
                  const inUse = record.usage_count > 0;
                  return (
                    <tr key={record.id} className={record.is_active ? "hover:bg-slate-50" : "bg-slate-50 text-slate-500"}>
                      <td className="px-4 py-2.5 font-semibold text-slate-800">
                        {label(record)}
                        {reserved && <span className="ml-2 rounded bg-purple-100 px-1.5 py-0.5 text-[9px] font-bold uppercase text-purple-800">System</span>}
                      </td>
                      {columns.map(([heading, render]) => <td key={heading} className="px-4 py-2.5">{render(record)}</td>)}
                      <td className="px-4 py-2.5">{record.usage_count}</td>
                      <td className="px-4 py-2.5">
                        <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${record.is_active ? "bg-emerald-100 text-emerald-800" : "bg-slate-200 text-slate-600"}`}>{record.is_active ? "Active" : "Inactive"}</span>
                      </td>
                      <td className="px-4 py-2.5">
                        <div className="flex justify-end gap-1">
                          <button type="button" onClick={() => openEdit(record)} disabled={busy} title="Edit" aria-label={`Edit ${label(record)}`} className="rounded p-1.5 text-slate-600 hover:bg-slate-100 disabled:opacity-40"><Pencil size={14} /></button>
                          <button
                            type="button"
                            onClick={() => toggleActive(record)}
                            disabled={busy || (reserved && record.is_active)}
                            title={reserved && record.is_active ? "Required by the system" : record.is_active ? "Deactivate" : "Re-activate"}
                            aria-label={`${record.is_active ? "Deactivate" : "Re-activate"} ${label(record)}`}
                            className={`rounded p-1.5 hover:bg-slate-100 disabled:opacity-40 ${record.is_active ? "text-amber-700" : "text-emerald-700"}`}
                          ><Power size={14} /></button>
                          <button
                            type="button"
                            onClick={() => remove(record)}
                            disabled={busy || inUse || reserved}
                            title={reserved ? "Required by the system" : inUse ? "In use — deactivate instead" : "Remove"}
                            aria-label={`Remove ${label(record)}`}
                            className="rounded p-1.5 text-rose-700 hover:bg-rose-50 disabled:opacity-40"
                          ><Trash2 size={14} /></button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
  );
}
