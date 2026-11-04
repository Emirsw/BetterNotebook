import katex from 'katex';
import autoRender from 'katex/dist/contrib/auto-render.js';

const options={throwOnError:false,strict:false,trust:false,maxExpand:500,maxSize:20};
export function renderMathContent(element){
  if(!element)return;
  // Editor HTML saves the LaTeX attribute, not KaTeX's presentation markup.
  // These empty nodes must also be typeset in the read-only view.
  const selector='[data-type="inline-math"][data-latex],[data-type="block-math"][data-latex]';
  const nodes=[...(element.matches?.(selector)?[element]:[]),...element.querySelectorAll(selector)];
  for(const node of nodes){
    if(node.closest('.bn-rich-content')||node.dataset.bnMathRendered==='1')continue;
    const latex=node.getAttribute('data-latex')||'';
    node.dataset.bnMathRendered='1';node.classList.add('bn-saved-math');
    try{katex.render(latex,node,{...options,macros:{'\\*':'*'},displayMode:node.dataset.type==='block-math'});}
    catch{node.textContent=latex;}
  }
  autoRender(element,{...options,macros:{'\\*':'*'},delimiters:[{left:'$$',right:'$$',display:true},{left:'\\[',right:'\\]',display:true},{left:'$',right:'$',display:false},{left:'\\(',right:'\\)',display:false}],ignoredClasses:['katex','bn-saved-math','bn-rich-content']});
  if(element.matches?.('.bn-live-markdown'))element.dataset.mathReady='1';
}
