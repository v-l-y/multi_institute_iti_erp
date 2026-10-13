import { api } from "../../core/http/client.js";
import { el, button, fileUpload, openDialog, notice, toast, validateForm, field } from "../../shared/ui/index.js";

function base64File(file, signal) {
  return new Promise((resolve,reject)=>{
    const reader = new FileReader();
    const stop = () => { reader.abort(); reject(new DOMException("Cancelled","AbortError")); };
    signal.addEventListener("abort",stop,{once:true});
    reader.addEventListener("load",()=>{
      signal.removeEventListener("abort",stop);
      const data=String(reader.result || "");
      const pos=data.indexOf(",");
      if(pos<0) reject(new Error("File reading failed."));
      else resolve(data.slice(pos+1));
    },{once:true});
    reader.addEventListener("error",()=>{
      signal.removeEventListener("abort",stop);
      reject(new Error("Could not read selected document."));
    },{once:true});
    reader.readAsDataURL(file);
  });
}

export function openUploadDialog(onDone) {
  const controller = new AbortController();
  let busy=false;
  const selected = fileUpload("document", "PDF / PNG / JPEG", {
    accept: ".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg",
    maxBytes: 5242880, required: true
  });
  const student = field("student_id","Institute student ID", { type:"number",min:"1",step:"1" });
  const feedback = el("div",{"aria-live":"assertive"});
  const cancel=button("Cancel");
  const submit=button("Upload document",{type:"submit",variant:"primary"});
  const form=el("form",{className:"stack-form"},student,selected.node,feedback,
    el("div",{className:"form-actions"},cancel,submit));
  const dialog=openDialog({
    title:"Upload student document",
    description:"Private institute storage only. Maximum 5 MB; verified student and permitted file content required.",
    content:form,
    onClose:()=>{controller.abort();selected.dispose();}
  });
  cancel.addEventListener("click",()=>{if(!busy)dialog.close();});
  dialog.addEventListener("cancel",event=>{if(busy)event.preventDefault();});
  form.addEventListener("submit",async event=>{
    event.preventDefault();
    if(busy||!selected.validate()||!validateForm(form))return;
    const file=selected.getFiles()[0];
    const studentId=Number(form.elements.namedItem("student_id").value);
    if(!file||!Number.isSafeInteger(studentId)||studentId<1||file.size>5242880||file.size===0){
      feedback.replaceChildren(notice("Invalid document","Select one nonempty file and a valid student ID.","error"));return;
    }
    busy=true;submit.disabled=cancel.disabled=true;feedback.replaceChildren();
    try{
      const encoded=await base64File(file,controller.signal);
      await api.post("/api/v1/documents/upload",{
        student_id:studentId,filename:file.name,content_base64:encoded
      },{signal:controller.signal});
      if(controller.signal.aborted||!dialog.open)return;
      dialog.close();toast("Document saved to private institute storage.");onDone?.();
    }catch(error){
      if(error?.name==="AbortError"||!dialog.open)return;
      feedback.replaceChildren(notice("Upload failed",error?.message||"Upload was rejected.","error"));
    }finally{busy=false;submit.disabled=cancel.disabled=false;}
  });
  return dialog;
}

export async function downloadDocument(doc, { own=false, signal }={}) {
  if(!Number.isSafeInteger(Number(doc.id))||Number(doc.id)<1)throw new Error("Invalid document ID.");
  const path=own?"/api/v1/documents/download/me":"/api/v1/documents/download";
  const blob=await api.download(path+"?document_id="+encodeURIComponent(doc.id),{signal});
  const filename="document-"+doc.id+({ "application/pdf":".pdf","image/png":".png","image/jpeg":".jpg" }[blob.type]||"");
  const url=URL.createObjectURL(blob);
  const link=document.createElement("a");
  link.href=url;link.download=filename;document.body.append(link);
  link.click();link.remove();
  window.setTimeout(()=>URL.revokeObjectURL(url),1000);
}
