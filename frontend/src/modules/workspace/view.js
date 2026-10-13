import { api, ApiError } from "../../core/http/client.js";
import {
  el, button, pageHeader, dataTable, tableFilters, filterRows, columnChooser,
  cursorPagination, skeleton, emptyState, errorPanel, openRecordDetails, notice, toast
} from "../../shared/ui/index.js";
import { workspace } from "./contracts.js";
import { permittedAction, openActionDialog } from "./action-dialog.js";
import { openUploadDialog, downloadDocument } from "./documents.js";

const positive = value => Number.isSafeInteger(Number(value)) && Number(value)>0;
function allowed(user, item) {
  const permission = user?.institute_id===null && item.platformPermission
    ? item.platformPermission : item.permission;
  return Boolean(user?.permissions?.includes(permission) &&
    (!item.identity || user?.[item.identity] != null));
}
const encodeQuery = entries => new URLSearchParams(entries).toString();

export function createWorkspaceView(page, main, user) {
  const config=workspace[page.workspace];
  if (!config) throw new Error("Unknown workspace");
  const datasets=config.datasets.filter(item=>allowed(user,item));
  const actions=config.actions.filter(item=>permittedAction(user,item));
  let active=datasets[0]||null;
  let rows=[],cursorHistory=[{}],pageIndex=0,nextCursor=null;
  let pending=null,disposed=false,modal=null,downloadPending=null;
  const count=el("span",{className:"muted","aria-live":"polite"});
  const panel=el("div",{"aria-busy":"false"});
  const feedback=el("div",{"aria-live":"polite"});
  const refresh=button("Refresh",{onClick:()=>void load()});
  const chooser=el("select",{"aria-label":"Register type",className:"table-filter-select"},
    ...datasets.map(item=>el("option",{value:item.id},item.label)));
  const filterSlot=el("div");
  const requiredSlot=el("div");
  const actionsSlot=el("div",{className:"heading-actions"});
  let actionSelect;
  if(actions.length) {
    actionSelect=el("select",{"aria-label":"Choose institute action",className:"table-filter-select"},
      ...actions.map((item,i)=>el("option",{value:String(i)},item.label)));
    actionsSlot.append(actionSelect,button("Open form",{variant:"primary",onClick:()=>{
      const spec=actions[Number(actionSelect.value)];
      if(!spec ||disposed)return;
      if(modal?.open)modal.close();
      modal=openActionDialog(spec,{user,onDone:()=>{if(!disposed)void load();}});
    }}));
  }
  if(page.workspace==="documents" && user.permissions.includes("document.manage")) {
    actionsSlot.append(button("Upload document",{variant:"primary",onClick:()=>{
      if(disposed)return;
      if(modal?.open)modal.close();
      modal=openUploadDialog(()=>{if(!disposed)void load();});
    }}));
  }
  const pager=cursorPagination({
    onPrevious:()=>{if(pageIndex<1||pending||disposed)return;pageIndex--;void load();},
    onNext:()=>{
      if(!nextCursor||pending||disposed)return;
      cursorHistory=cursorHistory.slice(0,pageIndex+1);
      cursorHistory.push(nextCursor);pageIndex++;void load();
    }
  });
  let filter=null,columns=null;
  let requiredInput=null;
  const tools=el("div",{className:"register-tools"},
    datasets.length?el("label",{className:"field"},el("span",{},"Register"),chooser)
      :el("p",{className:"muted"},"No register permission has been granted."),
    requiredSlot,filterSlot);
  main.replaceChildren(
    pageHeader(config.title,config.description,el("div",{className:"heading-actions"},refresh,actionsSlot)),
    feedback,
    el("section",{className:"surface register-surface"},
      tools,panel,el("div",{className:"register-bottom"},count,pager.node))
  );

  function resetControls(){
    rows=[];nextCursor=null;cursorHistory=[{}];pageIndex=0;
    filterSlot.replaceChildren();requiredSlot.replaceChildren();
    requiredInput=null;
    if(!active)return;
    filter=tableFilters({columns:active.columns,onChange:()=>render()});
    columns=columnChooser(active.columns,{requiredKeys:["id"],onChange:()=>render()});
    filterSlot.append(filter.node,columns.node);
    if(active.requiredQuery) {
      const q=active.requiredQuery;
      requiredInput=el("input",{type:"number",min:"1",step:"1",className:"search-input",
        placeholder:q.label,"aria-label":q.label});
      const loadButton=button("Load statement",{onClick:()=>{
        if(!positive(requiredInput.value)){feedback.replaceChildren(
          notice("Student ID required","Enter a valid student ID to retrieve a statement.","error"));return;}
        cursorHistory=[{}];pageIndex=0;void load();
      }});
      requiredSlot.append(el("div",{className:"erp-query-input"},requiredInput,loadButton));
    }
  }

  function rowActions(record) {
    const list=[{label:"View",ariaLabel:"View record "+String(record.id??record.item_code??record.enrollment_id??"details"),
      onClick:()=>{
        if(modal?.open)modal.close();
        modal=openRecordDetails(record,active.columns,{title:active.label+" record"});
      }}];
    if(page.workspace==="documents") {
      list.push({label:"Download",onClick:async()=>{
        if(disposed||downloadPending)return;
        const controller=new AbortController();downloadPending=controller;
        feedback.replaceChildren();
        try{
          await downloadDocument(record,{own:active.id==="mine",signal:controller.signal});
        }catch(error){if(!disposed && error?.name!=="AbortError")feedback.replaceChildren(
          notice("Download unsuccessful",error?.message||"Unable to download.","error"));}
        finally{if(downloadPending===controller)downloadPending=null;}
      }});
      if(user.permissions.includes("document.manage"))list.push({
        label:"Archive",danger:true,onClick:()=>{
          if(modal?.open)modal.close();
          modal=openActionDialog({
            label:"Archive document",path:"/api/v1/documents/archive",permission:"document.manage",
            fields:[{name:"document_id",label:"Document ID",kind:"id"},
              {name:"reason",label:"Archival reason",maxLength:1000}],
            description:"An audited archive action removes the document from active listings; it is not a physical deletion."
          },{user,initial:{document_id:record.id},onDone:()=>{if(!disposed)void load();}});
        }
      });
    }
    return list;
  }

  function render(){
    if(disposed||!active)return;
    const chosen=columns.getColumns();
    const visible=filterRows(rows,{...filter.read(),searchKeys:chosen.map(([key])=>key)});
    count.textContent=visible.length+" of "+rows.length+" records on this page";
    panel.replaceChildren(visible.length?
      dataTable(visible,chosen,{caption:active.label+" current page",rowActions})
      :emptyState(rows.length?"No matching records":"No records returned",
        rows.length?"Adjust the current-page filters.":"No source-backed records in this page."));
  }

  function refreshPager(busy=false){
    refresh.disabled=busy;
    chooser.disabled=busy;
    pager.update({page:pageIndex+1,hasNext:Boolean(nextCursor),busy});
  }

  async function load(){
    if(disposed||!active)return;
    pending?.abort();
    if(requiredInput&&!positive(requiredInput.value)){
      panel.replaceChildren(emptyState("Student ID required",
        "Enter the student ID and select Load statement."));
      count.textContent="";nextCursor=null;refreshPager();
      return;
    }
    const controller=new AbortController();pending=controller;
    nextCursor=null;refreshPager(true);panel.setAttribute("aria-busy","true");
    panel.replaceChildren(skeleton({rows:5,columns:Math.min(active.columns.length,6)}));
    feedback.replaceChildren();
    const q={page_size:"25",...(active.query||{}),...cursorHistory[pageIndex]};
    if(requiredInput)q[active.requiredQuery.key]=String(requiredInput.value);
    try{
      const data=await api.get(active.path+"?"+encodeQuery(q),{signal:controller.signal});
      if(disposed||pending!==controller)return;
      if(!data||!Array.isArray(data[active.key]))throw new ApiError("Invalid "+active.label+" API response.","INVALID_RESPONSE");
      rows=data[active.key];
      const raw=active.cursor==="next_cursor"?data.next_cursor:
        data.next_after_id===null||data.next_after_id===undefined?null:{after_id:data.next_after_id};
      nextCursor=raw&&typeof raw==="object"&&Object.keys(raw).length?
        Object.fromEntries(Object.entries(raw).map(([key,val])=>[key,String(val)])):null;
      if(nextCursor && Object.values(nextCursor).some(v=>v===""||v==="null"))nextCursor=null;
      render();
    }catch(error){
      if(error?.name==="AbortError"||disposed||pending!==controller)return;
      rows=[];count.textContent="Records unavailable";
      panel.replaceChildren(errorPanel(error?.message||"Request failed.",
        {onRetry:()=>void load()}));
    }finally{
      if(pending===controller){
        pending=null;panel.setAttribute("aria-busy","false");refreshPager();
      }
    }
  }
  chooser.addEventListener("change",()=>{
    if(pending)pending.abort();
    pending=null;
    active=datasets.find(item=>item.id===chooser.value)||null;
    resetControls();void load();
  });
  resetControls();refreshPager();
  return {
    mount(){return load();},
    dispose(){
      disposed=true;pending?.abort();downloadPending?.abort();
      if(modal?.open)modal.close();
    }
  };
}
