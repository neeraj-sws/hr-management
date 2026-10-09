export type AttendanceSession = {
  id: number;
  work_date: string;
  check_in: string | null;
  check_out: string | null;
  active_seconds: number;
  idle_seconds: number;
  break_seconds: number;
  status: 'working' | 'on_break' | 'completed' | string;
  server_version: number;
  worked_seconds?: number;
  events?: { event_type: string; event_time: string; duration_seconds: number }[];
};

export type Me = {
  id: number;
  name: string;
  email: string;
  roles: string[];
  permissions: string[];
  employee: {
    id: number;
    employee_code: string;
    name: string;
    email: string | null;
    department: { id: number; name: string } | null;
    manager: { id: number; name: string } | null;
    joined_at: string | null;
  } | null;
  today: AttendanceSession | null;
  unread_notifications: number;
  schedule: { shift: string | null; starts_at: string | null; grace_minutes: number } | null;
  policies: { location_required: boolean; away_prompt_minutes: number };
};

export type Task = {
  id: number;
  title: string;
  description: string | null;
  project: { id: number; name: string } | null;
  assignee: { id: number; name: string } | null;
  priority: string;
  status: string;
  estimated_hours: number | null;
  logged_seconds: number;
  due_on: string | null;
  overdue: boolean;
  completed_late: boolean;
  timer_running_since: string | null;
  completed_at: string | null;
  server_version: number;
};

export type LeaveType = { id: number; name: string; paid: boolean; allow_advance: boolean };
export type LeaveBalance = { leave_type: { id: number; name: string; paid: boolean }; entitled: number; accrued: number; adjusted: number; carried: number; used: number; booked: number; remaining: number };
export type LeaveRequest = { id: number; leave_type: string; starts_on: string; ends_on: string; days: number; reason: string; status: string; decision_note: string | null };
export type Overtime = { id: number; work_date: string; requested_minutes: number; approved_minutes: number | null; reason: string; status: string; decision_note: string | null };
export type Payslip = { id: number; reference: string; issued_at: string; period_start: string; period_end: string; gross: number; net: number; paid: number; balance: number; breakdown?: Record<string, unknown>; payments?: { paid_on: string; amount: number; reference: string | null }[] };
export type Notification = { id: string; kind: string | null; message: string; read_at: string | null; created_at: string };
export type Approval = { id: number; type: string; label: string; summary: string; created_at: string };
export type Paginated<T> = { data: T[]; total: number; current_page: number; last_page: number };
export type Correction = { id: number; work_date: string; check_in: string | null; check_out: string | null; reason: string; status: string; decision_note: string | null };
export type ExpenseClaim = { id: number; category: string; spent_on: string; amount: number; description: string; has_receipt: boolean; status: string; decision_note: string | null };
export type Announcement = { id: number; title: string; body: string; pinned: boolean; published_at: string; author: string | null };
export type Celebration = { employee: string; type: 'birthday' | 'anniversary'; date: string; years: number | null; today: boolean };
export type Holiday = { date: string; name: string };
export type Training = { id: number; title: string; description: string | null; category: string | null; duration_hours: number | null; material_url: string | null; mandatory: boolean; due_on: string | null; status: string; completed_at: string | null };
