/**
 * Dedicated ERP workspaces; each register/action independently checks its
 * permission. PHP remains the authority for tenant scope and transitions.
 */
export const routes = [
  { id:"fees",label:"Fees management",group:"Finance",icon:"₹",scope:"institute",
    permission:"fee.view",anyPermissions:["fee.view","fee.view.own","fee.record","fee.refund"],
    type:"workspace",workspace:"fees",path:"/api/v1/fees/payments",columns:[] },
  { id:"attendance",label:"Attendance",group:"Academics",icon:"▦",scope:"institute",
    permission:"attendance.view",
    anyPermissions:["attendance.view","attendance.view.assigned","attendance.view.own","attendance.mark.assigned","attendance.correct","calendar.manage","audit.view"],
    type:"workspace",workspace:"attendance",path:"/api/v1/attendance/records",columns:[] },
  { id:"ojt",label:"OJT / Projects",group:"Academics",icon:"◷",scope:"institute",
    permission:"ojt.view",
    anyPermissions:["ojt.view","ojt.view.own","ojt.log.own","ojt.evaluate.assigned","calendar.manage"],
    type:"workspace",workspace:"ojt",path:"/api/v1/ojt/projects",columns:[] },
  { id:"formative",label:"Assessments",group:"Academics",icon:"▤",scope:"institute",
    permission:"formative.template.manage",
    anyPermissions:["formative.template.manage","formative.record.assigned","formative.view.own","audit.view"],
    type:"workspace",workspace:"formative",path:"/api/v1/formative/records",columns:[] },
  { id:"syllabus",label:"CTS Syllabus",group:"Academics",icon:"▧",scope:"institute",
    permission:"curriculum.view",anyPermissions:["curriculum.view","curriculum.manage"],
    type:"workspace",workspace:"syllabus",path:"/api/v1/syllabus/curricula",columns:[] },
  { id:"documents",label:"Documents",group:"People",icon:"▣",scope:"institute",
    permission:"document.view",anyPermissions:["document.view","document.view.own","document.manage"],
    type:"workspace",workspace:"documents",path:"/api/v1/documents",columns:[] },
  { id:"platform-syllabus",label:"DGT Syllabus",group:"Platform",icon:"▧",scope:"platform",
    permission:"platform.regulatory.manage",
    type:"workspace",workspace:"syllabus",path:"/api/v1/syllabus/curricula",columns:[] }
];
