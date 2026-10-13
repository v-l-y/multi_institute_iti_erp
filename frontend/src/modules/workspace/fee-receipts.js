import { api } from "../../core/http/client.js";
import { el, button, notice, openRecordDetails, printCurrentPage } from "../../shared/ui/index.js";

const receiptColumns=[
  ["id","Payment ID"],["student_id","Student ID"],["receipt_no","Receipt"],
  ["payment_no","Payment reference"],["paid_on","Date"],["amount","Amount"],
  ["payment_mode","Mode"],["reference_no","Reference"],["status","Status"],
  ["reversal_no","Reversal reference"]
];

function downloadBlob(blob,filename) {
  const url=URL.createObjectURL(blob);
  const link=document.createElement("a");
  link.href=url;link.download=filename;document.body.append(link);link.click();link.remove();
  window.setTimeout(()=>URL.revokeObjectURL(url),1000);
}

export function feeReceiptTools(user, { signal }={}) {
  const canAll=user.permissions.includes("fee.view");
  const canOwn=user.permissions.includes("fee.view.own") && user.student_id!=null;
  if(!canAll&&!canOwn)return null;
  const own=!canAll;
  const payment=el("input",{type:"number",min:"1",step:"1","aria-label":"Payment ID",
    placeholder:"Payment ID",className:"search-input"});
  const feedback=el("div",{"aria-live":"polite"});
  let verifiedReceipt=null;
  const print=button("Print receipt",{disabled:true,onClick:()=>{
    if(!verifiedReceipt)return;
    printCurrentPage([verifiedReceipt],receiptColumns,{
      title:"Institute fee receipt",authorize:()=>canAll||canOwn
    });
  }});
  payment.addEventListener("input",()=>{verifiedReceipt=null;print.disabled=true;});
  const lookup=button("View receipt",{onClick:async()=>{
    const id=Number(payment.value);
    if(!Number.isSafeInteger(id)||id<1){
      feedback.replaceChildren(notice("Payment ID required","Provide a positive payment ID.","error"));return;
    }
    lookup.disabled=true;feedback.replaceChildren();
    try{
      const path=own?"/api/v1/fees/receipts/me":"/api/v1/fees/receipts";
      const receipt=await api.get(path+"?payment_id="+encodeURIComponent(id),{signal});
      if(signal?.aborted)return;
      verifiedReceipt=receipt;print.disabled=false;
      const identity=receipt.identity_snapshot;
      const display={...receipt,
        recorded_student:identity?.student_name??"",
        recorded_institute:identity?.institute?.name??""};
      const fields=[...receiptColumns,["recorded_student","Student at payment"],["recorded_institute","Institute at payment"]];
      openRecordDetails(display,fields,{title:"Fee receipt "+String(receipt.receipt_no)});
    }catch(error){
      if(error?.name!=="AbortError"&&!signal?.aborted)feedback.replaceChildren(
        notice("Receipt unavailable",error?.message||"No receipt found.","error"));
    }finally{lookup.disabled=false;}
  }});
  const output=el("div",{className:"erp-receipt-tools"},
    el("label",{className:"field"},el("span",{},"Receipt"),payment),
    lookup,print,feedback);
  return output;
}

export function feeStatementExport(user, { getStudentId, signal, onError }={}) {
  const canAll=user.permissions.includes("fee.view");
  const canOwn=user.permissions.includes("fee.view.own")&&user.student_id!=null;
  if(!canAll&&!canOwn)return null;
  const buttonExport=button("Export statement CSV",{onClick:async()=>{
    const id=canAll?Number(getStudentId?.()):null;
    if(canAll && (!Number.isSafeInteger(id)||id<1)){
      onError?.("Select a valid student ID for statement export.");return;
    }
    buttonExport.disabled=true;
    try{
      const path=canAll?"/api/v1/fees/statements/export":"/api/v1/fees/statements/export/me";
      const params=new URLSearchParams({page_size:"100"});
      if(canAll)params.set("student_id",String(id));
      const blob=await api.download(path+"?"+params.toString(),
        {signal,acceptedTypes:["text/csv","application/csv"]});
      if(!signal?.aborted)downloadBlob(blob,"fee-statement-page.csv");
    }catch(error){if(error?.name!=="AbortError")onError?.(error?.message||"Export failed.");}
    finally{buttonExport.disabled=false;}
  }});
  return buttonExport;
}
