import React from "react";
import { createPortal } from "react-dom";

const DOCUMENT_TYPES = {
  APPOINTMENT_LETTER: "Appointment Letter",
  CONFIRMATION_GAZETTE: "Confirmation Gazette",
  DEGREE_CERTIFICATE: "Degree Certificate",
  NYSC_DISCHARGE: "NYSC Discharge",
  PASSPORT_PHOTO: "Passport Photograph",
  PROMOTION_LETTER: "Promotion Letter",
  OTHER: "Other",
};

const fullName = (staff) => [staff.title, staff.surname ? `${staff.surname},` : "", staff.first_name, staff.middle_name].filter(Boolean).join(" ");
const formatSize = (bytes) => (bytes ? `${(bytes / 1024).toFixed(0)} KB` : "—");
const formatDate = (value) => (value ? String(value).slice(0, 10) : "—");

function Details({ fields }) {
  return (
    <dl className="profile-print-grid">
      {Object.entries(fields).map(([label, value]) => (
        <div key={label}><dt>{label}</dt><dd>{value === 0 || value ? value : "—"}</dd></div>
      ))}
    </dl>
  );
}

function RecordTable({ title, columns, rows }) {
  return (
    <section>
      <h2>{title}</h2>
      {rows.length ? (
        <table>
          <thead><tr>{columns.map(([label]) => <th key={label}>{label}</th>)}</tr></thead>
          <tbody>
            {rows.map((row, index) => (
              <tr key={row.id ?? index}>{columns.map(([label, render]) => <td key={label}>{render(row) || "—"}</td>)}</tr>
            ))}
          </tbody>
        </table>
      ) : <p>None recorded.</p>}
    </section>
  );
}

export default function StaffProfilePrint({ data }) {
  if (!data) return null;
  const { staff } = data;
  const events = (type) => data.events.filter((event) => event.type === type);
  const age = staff.date_of_birth ? Math.floor((Date.now() - new Date(staff.date_of_birth)) / 31557600000) : null;

  return createPortal(
    <article className={`staff-profile-print ${staff.is_active === false ? "staff-inactive" : ""}`} aria-label="Staff profile printout">
      <div className="profile-print-letterhead">
        <img src="/images/ogun_state_logo.png" alt="Ogun State crest" />
        <div>
          <strong>OGUN STATE GOVERNMENT · BUREAU OF INFORMATION TECHNOLOGY</strong>
          <span>Staff Personal Record (Official)</span>
        </div>
      </div>

      <div className="profile-print-identity">
        {staff.photo_path
          ? <img className="profile-print-photo" src={staff.photo_path} alt="Staff passport photograph" />
          : <div className="profile-print-photo profile-print-photo-empty">No passport photograph</div>}
        <div>
          <h1>{fullName(staff)}</h1>
          <p><b>Staff ID:</b> {staff.staff_number || "—"} &nbsp;·&nbsp; <b>OG No:</b> {staff.ogun_employee_number || "—"} &nbsp;·&nbsp; <b>File No:</b> {staff.file_number || "—"}</p>
          <p>{[staff.designation?.name, staff.grade_level?.name && `${staff.grade_level.name}, Step ${staff.step ?? "—"}`, staff.department?.name].filter(Boolean).join(" · ")}</p>
          <p><b>Status:</b> {staff.status?.name || "—"} &nbsp;·&nbsp; <b>Station:</b> {staff.current_station || "—"}</p>
          <p><b>Record status:</b> {staff.is_active === false ? "Inactive (read-only)" : "Active"}</p>
        </div>
      </div>

      <section>
        <h2>Bio-data</h2>
        <Details fields={{
          Title: staff.title,
          Surname: staff.surname,
          "First name": staff.first_name,
          "Middle name": staff.middle_name,
          Gender: staff.gender,
          "Date of birth": formatDate(staff.date_of_birth),
          Age: age,
          "Place of birth": staff.place_of_birth,
          "State of origin": staff.state_of_origin,
          "LGA of origin": staff.lga_of_origin,
          Qualification: staff.qualification,
          "Phone number": staff.phone_number,
          "Official email": staff.official_email,
          "Personal email": staff.personal_email,
          "Residential address": staff.residential_address,
        }} />
      </section>

      <section>
        <h2>Service record</h2>
        <Details fields={{
          "Staff ID": staff.staff_number,
          "OG employee no.": staff.ogun_employee_number,
          "File number": staff.file_number,
          "IPPIS number": staff.ippis_number,
          Department: staff.department?.name,
          Unit: staff.unit?.name,
          Designation: staff.designation?.name,
          Cadre: staff.designation?.cadre_name,
          "Grade level": staff.grade_level?.name,
          Step: staff.step,
          "Salary structure": staff.grade_level?.salary_structure,
          "Employment status": staff.status?.name,
          "Current station": staff.current_station,
          "First appointment": formatDate(staff.date_of_first_appointment),
          Confirmation: formatDate(staff.date_of_confirmation),
          "Present appointment": formatDate(staff.date_of_present_appointment),
          "Last promotion": formatDate(staff.date_of_last_promotion),
          "Last deployment": formatDate(staff.date_of_last_deployment),
          "Expected retirement": formatDate(staff.expected_retirement_date),
          Remarks: staff.remarks,
        }} />
      </section>

      <RecordTable title="Postings and deployments" rows={data.postings} columns={[
        ["Station", (item) => item.station_name],
        ["MDA", (item) => item.mda_name],
        ["Type", (item) => item.deployment_type],
        ["Effective", (item) => formatDate(item.effective_date)],
        ["End", (item) => (item.end_date ? formatDate(item.end_date) : item.is_current ? "Current" : "")],
        ["Reference / remarks", (item) => [item.circular_reference, item.remarks].filter(Boolean).join(" · ")],
      ]} />

      <RecordTable title="Personal folder documents" rows={data.documents} columns={[
        ["Type", (item) => DOCUMENT_TYPES[item.document_type] || item.document_type],
        ["Description", (item) => item.title],
        ["File", (item) => item.file_name],
        ["Size", (item) => formatSize(item.file_size)],
        ["Uploaded", (item) => formatDate(item.created_at)],
      ]} />

      <RecordTable title="Promotion history" rows={events("PROMOTION")} columns={[
        ["Effective date", (event) => formatDate(event.start_date || event.occurred_on)],
        ["Promotion", (event) => event.title],
        ["From", (event) => event.from_grade_level?.name],
        ["To", (event) => event.to_grade_level?.name],
        ["Reference / remarks", (event) => [event.circular_reference, event.remarks].filter(Boolean).join(" · ")],
      ]} />

      <RecordTable title="Leave history" rows={events("LEAVE")} columns={[
        ["Leave", (event) => [event.title, event.leave_type].filter(Boolean).join(" · ")],
        ["Period", (event) => `${formatDate(event.start_date || event.occurred_on)}${event.end_date ? ` to ${formatDate(event.end_date)}` : ""}`],
        ["Days", (event) => event.leave_days],
        ["Resumed", (event) => (event.resumed_on ? formatDate(event.resumed_on) : "")],
        ["Remarks", (event) => [event.circular_reference, event.remarks].filter(Boolean).join(" · ")],
      ]} />

      {events("DISCIPLINARY").length > 0 && <RecordTable title="Disciplinary actions" rows={events("DISCIPLINARY")} columns={[
        ["Date", (event) => formatDate(event.occurred_on)],
        ["Matter", (event) => event.title],
        ["Reason", (event) => event.reason],
        ["Officer response", (event) => event.staff_response],
        ["Action / outcome", (event) => [event.disciplinary_action, event.action_taken].filter(Boolean).join(" — ")],
        ["Current state", (event) => event.current_state],
      ]} />}

      <RecordTable title="Training and professional development" rows={events("TRAINING")} columns={[
        ["Programme", (event) => event.title],
        ["Type", (event) => event.training_type],
        ["Provider", (event) => event.provider],
        ["Period", (event) => `${formatDate(event.start_date || event.occurred_on)}${event.end_date ? ` to ${formatDate(event.end_date)}` : ""}`],
        ["Qualification", (event) => event.qualification_obtained],
      ]} />

      {data.confidential_included && <>
        <RecordTable title="Confidential: annual performance ratings" rows={data.confidential_ratings} columns={[
          ["Year", (item) => item.rating_year],
          ["Rating / 5", (item) => item.rating],
          ["Comments", (item) => item.comments],
        ]} />
        <RecordTable title="Confidential: document register" rows={data.confidential_documents} columns={[
          ["Category", (item) => item.category],
          ["Description", (item) => item.title],
          ["File", (item) => item.file_name],
          ["Uploaded", (item) => formatDate(item.created_at)],
        ]} />
      </>}

      <div className="profile-print-signatures">
        <div>Prepared by (HR Officer)</div>
        <div>Verified by (Head, Human Resources)</div>
      </div>
      <footer>BIT-HRMS · {fullName(staff)} · {staff.staff_number} · Generated {new Date().toLocaleString()}</footer>
    </article>,
    document.body
  );
}
