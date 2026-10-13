/**
 * Actual GET contracts from backend/routes/api.php and controller/repositories.
 * Each register opts into the backend's exact row key + cursor format.
 */
const cols = entries => entries;
const dataset = (id, label, path, permission, key, columns, options = {}) =>
  ({ id, label, path, permission, key, columns: cols(columns), ...options });
const id = (name, label) => ({ name, label, kind: "id" });
const text = (name, label, maxLength = 190, options = {}) =>
  ({ name, label, maxLength, ...options });
const date = (name, label, required = true) => ({ name, label, type: "date", required });
const decimal = (name, label) => ({ name, label, maxLength: 18,
  description: "Decimal string supplied by institute source record." });
const action = (label, path, permission, fields, options = {}) =>
  ({ label, path, permission, fields, ...options });

export const workspace = {
  fees: {
    title: "Fees management",
    description: "Institute fee structures, assessments, demands, payments and reversals. No invented charge schedules.",
    datasets: [
      dataset("payments","Payments","/api/v1/fees/payments","fee.view","payments",
        [["id","ID"],["student_id","Student ID"],["payment_no","Payment"],["receipt_no","Receipt"],["paid_on","Paid"],["amount","Amount"],["payment_mode","Mode"]]),
      dataset("heads","Fee heads","/api/v1/fees/heads","fee.view","records",
        [["id","ID"],["code","Code"],["name","Name"],["requires_enrollment","Requires enrollment"],["is_active","Active"]]),
      dataset("structures","Structures","/api/v1/fees/structures","fee.view","records",
        [["id","ID"],["trade_id","Trade ID"],["version_no","Version"],["effective_from","From"],["effective_to","To"],["status","Status"]]),
      dataset("items","Structure items","/api/v1/fees/structure-items","fee.view","records",
        [["id","ID"],["structure_id","Structure"],["head_id","Fee head"],["amount","Amount"]]),
      dataset("assessments","Assessments","/api/v1/fees/assessments","fee.view","records",
        [["id","ID"],["student_id","Student"],["enrollment_id","Enrollment"],["structure_id","Structure"],["assessment_no","Number"],["status","Status"]]),
      dataset("demands","Demands","/api/v1/fees/demands","fee.view","records",
        [["id","ID"],["student_id","Student"],["assessment_id","Assessment"],["demand_no","Demand"],["due_on","Due"],["status","Status"]]),
      dataset("refunds","Refunds","/api/v1/fees/refunds","fee.view","records",
        [["id","ID"],["student_id","Student"],["allocation_id","Allocation"],["amount","Amount"],["refund_no","Refund"],["status","Status"]]),
      dataset("waivers","Waivers","/api/v1/fees/waivers","fee.view","records",
        [["id","ID"],["student_id","Student"],["assessment_id","Assessment"],["amount","Amount"],["status","Status"],["reason","Reason"]]),
      dataset("reversals","Reversals","/api/v1/fees/reversals","fee.view","records",
        [["id","ID"],["payment_id","Payment"],["reversal_no","Number"],["reason","Reason"],["requested_by","Requester"],["approved_by","Approver"]]),
      dataset("own-payments","My payments","/api/v1/fees/payments/me","fee.view.own","payments",
        [["id","ID"],["payment_no","Payment"],["receipt_no","Receipt"],["paid_on","Paid"],["amount","Amount"]]),
      dataset("statement","Student statement","/api/v1/fees/statements","fee.view","demands",
        [["id","ID"],["demand_no","Demand"],["due_on","Due"],["gross","Gross"],["waived","Waived"],["net_due","Net due"],["effective_paid","Paid"],["outstanding","Outstanding"]],
        { requiredQuery: { key: "student_id", label: "Student ID", type: "id" } }),
      dataset("own-statement","My statement","/api/v1/fees/statements/me","fee.view.own","demands",
        [["id","ID"],["demand_no","Demand"],["due_on","Due"],["gross","Gross"],["waived","Waived"],["net_due","Net due"],["effective_paid","Paid"],["outstanding","Outstanding"]])
    ],
    actions: [
      action("Create fee head","/api/v1/fees/heads","fee.record",[text("code","Code",50),text("name","Name",120),{name:"requires_enrollment",label:"Requires enrollment",kind:"boolean"}]),
      action("Draft fee structure","/api/v1/fees/structures","fee.record",[id("trade_id","Trade ID"),id("version_no","Version number"),date("effective_from","Effective from"),date("effective_to","Effective to",false)]),
      action("Add structure item","/api/v1/fees/structures/items","fee.record",[id("structure_id","Structure ID"),id("head_id","Head ID"),decimal("amount","Amount")]),
      action("Activate fee structure","/api/v1/fees/structures/activate","fee.record",[id("structure_id","Structure ID")]),
      action("Draft fee assessment","/api/v1/fees/assessments","fee.record",[id("student_id","Student ID"),{...id("enrollment_id","Enrollment ID"),required:false},id("structure_id","Structure ID"),text("assessment_no","Assessment number",100),date("assessed_on","Assessed on")]),
      action("Issue assessment","/api/v1/fees/assessments/issue","fee.record",[id("assessment_id","Assessment ID")]),
      action("Issue demand","/api/v1/fees/demands","fee.record",[id("assessment_id","Assessment ID"),text("demand_no","Demand number",100),date("due_on","Due on")]),
      action("Record payment","/api/v1/fees/payments","fee.record",[id("student_id","Student ID"),decimal("amount","Amount"),text("payment_mode","Recorded payment mode",20),text("payment_no","Payment number",100),text("receipt_no","Receipt number",100)],{idempotency:true}),
      action("Allocate payment","/api/v1/fees/allocations","fee.record",[id("payment_id","Payment ID"),id("demand_id","Demand ID"),decimal("amount","Amount")]),
      action("Request waiver","/api/v1/fees/waivers/request","fee.record",[id("assessment_id","Assessment ID"),text("waiver_no","Waiver number",100),decimal("amount","Amount"),text("reason","Reason",500)]),
      action("Approve waiver","/api/v1/fees/waivers/approve","fee.refund",[id("waiver_id","Waiver ID")]),
      action("Request refund","/api/v1/fees/refunds","fee.refund",[id("allocation_id","Allocation ID"),decimal("amount","Amount"),text("reason","Reason",500),text("refund_mode","Refund mode",20),text("refund_no","Refund number",100)],{idempotency:true}),
      action("Decide refund","/api/v1/fees/refunds/decide","fee.refund",[id("refund_id","Refund ID"),{name:"action",label:"Decision",options:[["approved","Approved"],["rejected","Rejected"],["cancelled","Cancelled"],["paid","Paid"]]},{...text("reference_no","Reference",190),required:false}]),
      action("Request payment reversal","/api/v1/fees/payments/reversal-request","fee.refund",[id("payment_id","Payment ID"),text("reversal_no","Reversal number",100),text("reason","Reason",500)]),
      action("Approve payment reversal","/api/v1/fees/payments/reverse","fee.refund",[id("payment_id","Payment ID"),id("requested_by","Requester user ID"),text("reversal_no","Reversal number",100),text("reason","Reason",500)],
        {description:"Independent requester identity and prior request are verified again by the backend."}),
      action("Record cash transfer","/api/v1/fees/cash/transfers","fee.record",
        [{name:"transfer_type",label:"Transfer type",options:[["bank_deposit","Bank deposit"],["cash_withdrawal","Cash withdrawal"],["counter_addition","Counter addition"]]},
          decimal("amount","Amount"),text("reference_no","Reference",190)],{idempotency:true}),
      action("Close cash day","/api/v1/fees/cash/close","fee.record",
        [date("closing_date","Closing date"),decimal("opening_amount","Opening balance"),
          decimal("actual_amount","Counted cash"),text("opening_reference","Opening evidence",190)]),
      action("Review cash closing","/api/v1/fees/cash/close/review","fee.refund",
        [id("closing_id","Closing ID"),{name:"decision",label:"Review decision",
          options:[["reviewed","Reviewed"],["requires_correction","Requires correction"]]},
          text("evidence_reference","Review evidence",190)],{role:"principal"})
    ]
  },
  attendance: {
    title:"Attendance",description:"Source-backed recorded attendance; corrections and eligibility remain backend-controlled.",
    datasets:[
      dataset("all","Institute records","/api/v1/attendance/records","attendance.view","records",
        [["session_id","Session"],["enrollment_id","Enrollment"],["session_date","Date"],["component","Component"],["effective_status","Effective status"],["effective_attended_minutes","Effective minutes"],["correction_id","Correction"]],
        { cursor:"next_cursor" }),
      dataset("assigned","Assigned records","/api/v1/attendance/records/assigned","attendance.view.assigned","records",
        [["session_id","Session"],["enrollment_id","Enrollment"],["session_date","Date"],["effective_status","Status"],["effective_attended_minutes","Minutes"]],
        { cursor:"next_cursor", identity:"staff_id" }),
      dataset("mine","My attendance","/api/v1/attendance/records/me","attendance.view.own","records",
        [["session_id","Session"],["session_date","Date"],["effective_status","Status"],["effective_attended_minutes","Minutes"],["correction_id","Correction"]],
        { cursor:"next_cursor", identity:"student_id" })
    ],
    actions:[
      action("Mark assigned attendance","/api/v1/attendance/records/assigned","attendance.mark.assigned",
        [id("session_id","Session ID"),id("enrollment_id","Enrollment ID"),
          {name:"attended_minutes",label:"Attended minutes",kind:"integer",min:0,max:65535},
          {name:"status",label:"Attendance status",options:["present","absent","leave","medical_leave","emergency_leave","ojt"].map(x=>[x,x])},
          {...text("leave_evidence_reference","Leave evidence reference",190),required:false}],
        {identity:"staff_id",role:"instructor"}),
      action("Create attendance session","/api/v1/attendance/sessions","calendar.manage",
        [id("batch_year_id","Batch year ID"),id("instructor_id","Assigned instructor staff ID"),
          date("session_date","Session date"),
          {name:"component",label:"CTS source component",options:["trade_practical","trade_theory","employability","ojt_project"].map(v=>[v,v])}]),
      action("Lock attendance session","/api/v1/attendance/sessions/lock","attendance.correct",
        [id("session_id","Session ID")],{role:"principal"}),
      action("Record reviewed attendance correction","/api/v1/attendance/corrections","attendance.correct",
        [id("session_id","Session ID"),id("enrollment_id","Enrollment ID"),
          {name:"attended_minutes",label:"Effective minutes",kind:"integer",min:0,max:65535},
          {name:"status",label:"Corrected status",options:["present","absent","leave","medical_leave","emergency_leave","ojt"].map(v=>[v,v])},
          {name:"source_id",label:"Correction source ID (0 if none)",kind:"integer",min:0},
          text("reason","Reason",1000),text("authority_reference","Authority reference",190),
          {name:"expected_correction_id",label:"Last correction ID (0 if none)",kind:"integer",min:0}])
    ]
  },
  ojt: {
    title:"OJT / Group projects",description:"Industry/trainee activities backed by applicable source records.",
    datasets:[
      dataset("projects","Institute projects","/api/v1/ojt/projects","ojt.view","projects",
        [["id","ID"],["enrollment_id","Enrollment"],["batch_year_id","Course year"],["mode","Mode"],["title","Project"],["industry_name","Industry"],["starts_on","Starts"],["ends_on","Ends"],["status","Status"]]),
      dataset("mine","My OJT","/api/v1/ojt/projects/me","ojt.view.own","projects",
        [["id","ID"],["title","Project"],["mode","Mode"],["starts_on","Starts"],["ends_on","Ends"],["status","Status"]],{identity:"student_id"})
    ],
    actions:[
      action("Plan OJT / project","/api/v1/ojt/projects","calendar.manage",
        [id("enrollment_id","Enrollment ID"),id("batch_year_id","Batch year ID"),
          {name:"mode",label:"Source-declared mode",options:[["ojt","OJT"],["group_project","Group project"]]},
          text("title","Project title",255),{...text("industry_name","Industry name",255),required:false},
          {...text("industry_registration_reference","Industry registration",190),required:false},
          {...text("workplace_address","Workplace address",16000,{multiline:true}),required:false},
          {...text("industry_mentor","Industry mentor",190),required:false},
          id("coordinator_id","Coordinator staff ID"),date("starts_on","Starts"),date("ends_on","Ends"),
          {...id("required_minutes","Source-required minutes"),max:4294967295},
          text("safety_reference","Safety evidence",190),{...text("female_safety_reference","Female safety evidence",190),required:false}],
        {role:"principal"}),
      action("Start OJT / project","/api/v1/ojt/projects/start","calendar.manage",
        [id("project_id","Project ID"),text("review_reference","Review evidence",500)],{role:"principal"}),
      action("Add my logbook entry","/api/v1/ojt/logbook/me","ojt.log.own",
        [id("project_id","Project ID"),date("entry_date","Entry date"),{...id("attended_minutes","Attended minutes"),max:1440},
          text("activity","Activity",16000,{multiline:true}),text("mentor_confirmation","Mentor confirmation",190),
          {...text("monitoring_safety_reference","Safety reference",190),required:false}],{role:"student",identity:"student_id"}),
      action("Record assigned evaluation","/api/v1/ojt/evaluations/assigned","ojt.evaluate.assigned",
        [id("project_id","Project ID"),{name:"primary_evaluator_role",label:"Evaluator role",options:[["industry_mentor","Industry mentor"],["trade_instructor","Trade instructor"]]},
          text("primary_evaluator_name","Primary evaluator",190),text("primary_marks","Primary marks",12),
          text("primary_max_marks","Primary maximum",12),text("external_examiner_name","External examiner",190),
          text("external_marks","External marks",12),text("external_max_marks","External maximum",12),
          {name:"final_grade",label:"Source-declared grade",options:["A1","A","B","C"].map(x=>[x,x])},
          date("evaluated_on","Evaluated on"),id("evaluation_source_id","Evaluation source ID"),
          text("signed_evidence_reference","Signed evidence",190),
          {...text("sidh_submission_reference","SIDH reference",190),required:false},
          {...text("completion_certificate_reference","Completion reference",190),required:false}],
        {role:"instructor",identity:"staff_id"})
    ]
  },
  formative: {
    title:"Formative assessments",description:"Recorded marks and reviewed corrections; no frontend eligibility calculation.",
    datasets:[
      dataset("points","Points assessments","/api/v1/formative/records","formative.template.manage","records",
        [["id","ID"],["enrollment_id","Enrollment"],["learning_outcome_id","LO"],["max_marks","Maximum"],["effective_obtained_marks","Effective marks"],["assessed_on","Assessed"],["locked_on","Locked"]],{cursor:"next_cursor",query:{type:"points"}}),
      dataset("template","Template assessments","/api/v1/formative/records","formative.template.manage","records",
        [["enrollment_id","Enrollment"],["batch_year_id","Year"],["item_code","Item"],["max_marks","Maximum"],["effective_obtained_marks","Effective marks"],["assessed_on","Assessed"],["locked_on","Locked"]],{cursor:"next_cursor",query:{type:"template"}}),
      dataset("assigned-points","Assigned points","/api/v1/formative/records/assigned","formative.record.assigned","records",
        [["id","ID"],["enrollment_id","Enrollment"],["learning_outcome_id","LO"],["max_marks","Max"],["effective_obtained_marks","Effective marks"]],{cursor:"next_cursor",query:{type:"points"},identity:"staff_id"}),
      dataset("assigned-template","Assigned template","/api/v1/formative/records/assigned","formative.record.assigned","records",
        [["enrollment_id","Enrollment"],["batch_year_id","Year"],["item_code","Item"],["max_marks","Max"],["effective_obtained_marks","Effective marks"]],{cursor:"next_cursor",query:{type:"template"},identity:"staff_id"}),
      dataset("mine-points","My points","/api/v1/formative/records/me","formative.view.own","records",
        [["id","ID"],["learning_outcome_id","LO"],["max_marks","Maximum"],["effective_obtained_marks","Effective marks"]],{cursor:"next_cursor",query:{type:"points"},identity:"student_id"}),
      dataset("mine-template","My template scores","/api/v1/formative/records/me","formative.view.own","records",
        [["batch_year_id","Year"],["item_code","Item"],["max_marks","Maximum"],["effective_obtained_marks","Effective marks"]],{cursor:"next_cursor",query:{type:"template"},identity:"student_id"})
    ],
    actions:[
      action("Record assigned LO assessment","/api/v1/formative/records/assigned","formative.record.assigned",
        [id("enrollment_id","Enrollment ID"),id("batch_year_id","Batch year ID"),id("learning_outcome_id","Learning outcome ID"),
          date("assessed_on","Assessed on"),text("max_marks","Maximum marks",12),text("obtained_marks","Obtained marks",12),
          text("evidence_reference","Evidence",190)],{role:"instructor",identity:"staff_id"}),
      action("Record assigned template score","/api/v1/formative/templates/scores/assigned","formative.record.assigned",
        [id("enrollment_id","Enrollment ID"),id("batch_year_id","Batch year ID"),text("item_code","Source item code",80),
          date("assessed_on","Assessed on"),text("obtained_marks","Obtained marks",10),text("evidence_reference","Evidence",500)],
        {role:"instructor",identity:"staff_id"}),
      action("Lock LO assessment","/api/v1/formative/records/lock","formative.template.manage",
        [id("formative_assessment_id","Assessment ID"),text("review_reference","Review evidence",500)],{role:"principal"}),
      action("Lock template score","/api/v1/formative/templates/scores/lock","formative.template.manage",
        [id("enrollment_id","Enrollment ID"),id("batch_year_id","Year ID"),text("item_code","Item",80),
          text("review_reference","Review evidence",500)],{role:"principal"}),
      action("Review formative scheme","/api/v1/formative/schemes/configure","formative.template.manage",
        [id("batch_year_id","Batch year ID"),id("regulatory_source_id","Published source ID"),
          {name:"formative_method",label:"Reviewed method",options:[["points_ratio","Points ratio"],["template_weighted","Weighted template"]]},
          text("review_reference","Review reference",500)],{role:"principal"}),
      action("Define reviewed formative template item","/api/v1/formative/templates/items","formative.template.manage",
        [id("batch_year_id","Batch year ID"),text("item_code","Item code",80),
          text("description","Item description",500),text("max_marks","Source maximum",12),
          text("weight_percent","Source weight percent",12),text("review_reference","Review reference",500)],{role:"principal"}),
      action("Correct unlocked template score","/api/v1/formative/templates/scores/correct-unlocked","formative.template.manage",
        [id("enrollment_id","Enrollment ID"),id("batch_year_id","Year ID"),text("item_code","Item code",80),
          text("obtained_marks","Corrected marks",10),date("assessed_on","Assessed date"),
          text("evidence_reference","Evidence",500),text("reason","Correction reason",500),
          text("review_reference","Principal review",500)],{role:"principal"}),
      action("Record reviewed formative correction","/api/v1/formative/corrections","formative.template.manage",
        [{...id("assessment_id","Assessment ID (points)"),required:false},
          {...text("item_code","Item code (template)",80),required:false},
          id("enrollment_id","Enrollment ID"),id("batch_year_id","Year ID"),
          text("obtained_marks","Effective marks",12),
          {name:"source_id",label:"Correction source ID (0 if none)",kind:"integer",min:0},
          text("reason","Reason",1000),text("authority_reference","Authority reference",190),
          {name:"expected_correction_id",label:"Last correction ID (0 if none)",kind:"integer",min:0}])
    ]
  },
  syllabus: {
    title:"CTS syllabus / curriculum",description:"Published DGT source-backed curriculum versions, course years and learning outcomes.",
    datasets:[
      dataset("curricula","Curriculum versions","/api/v1/syllabus/curricula","curriculum.view","records",
        [["id","Curriculum ID"],["dgt_code","Trade code"],["version_code","Version"],["duration_months","Months"],["nsqf_level","NSQF"],["unit_strength","Strength"],["source_reference","DGT source"]],{platformPermission:"platform.regulatory.manage"}),
      dataset("years","Course years and training minutes","/api/v1/syllabus/years","curriculum.view","records",
        [["id","Year ID"],["global_curriculum_id","Curriculum"],["year_no","Course year"],["trade_practical_minutes","Practical min"],["trade_theory_minutes","Theory min"],["employability_minutes","Employability min"],["ojt_project_minutes","OJT min"]],{platformPermission:"platform.regulatory.manage"}),
      dataset("outcomes","Learning outcomes and criteria","/api/v1/syllabus/outcomes","curriculum.view","records",
        [["id","ID"],["global_curriculum_year_id","Year ID"],["lo_code","LO code"],["nos_code","NOS"],["outcome_text","Learning outcome"],["assessment_criteria","Assessment criteria"],["formative_max_marks","Max marks"]],{platformPermission:"platform.regulatory.manage"})
    ],
    actions: [
      action("Link published curriculum to institute","/api/v1/academic/curriculum-links","curriculum.manage",
        [id("global_curriculum_id","Published global curriculum ID")]),
      action("Link curriculum course year","/api/v1/academic/curriculum-year-links","curriculum.manage",
        [id("curriculum_id","Institute curriculum ID"),id("global_curriculum_year_id","Global curriculum year ID")])
    ]
  },
  documents: {
    title:"Student documents",description:"Private institute-scoped documents: PDF, PNG or JPEG up to 5 MB, with audited storage.",
    datasets:[
      dataset("all","Institute documents","/api/v1/documents","document.view","documents",
        [["id","Document ID"],["student_id","Student ID"],["original_name","Filename"],["mime_type","File type"],["byte_size","Bytes"],["created_at","Uploaded"]]),
      dataset("mine","My documents","/api/v1/documents/me","document.view.own","documents",
        [["id","Document ID"],["original_name","Filename"],["mime_type","File type"],["byte_size","Bytes"],["created_at","Uploaded"]],{identity:"student_id"})
    ],
    actions:[]
  }
};
