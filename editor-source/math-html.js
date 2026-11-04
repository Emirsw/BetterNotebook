// Convert source LaTeX to persistent editor nodes, never mutate ProseMirror's DOM.
export function prepareMathHTML(html){
  const root=document.createElement('div');root.innerHTML=html;
  const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);
  const texts=[];while(walker.nextNode())texts.push(walker.currentNode);
  const pattern=/(?<!\\)(\$\$[\s\S]+?\$\$|\\\[[\s\S]+?\\\]|\\\([\s\S]+?\\\)|\$(?:\\[^\n]|[^$\\\n])+?\$)/g;
  for(const text of texts){
    if(!root.contains(text)||text.parentElement.closest('pre,code,[data-type="inline-math"],[data-type="block-math"],.katex,script,style'))continue;
    const source=text.nodeValue,matches=[...source.matchAll(pattern)];if(!matches.length)continue;
    const fragment=document.createDocumentFragment();let offset=0;
    for(const match of matches){
      fragment.append(document.createTextNode(source.slice(offset,match.index)));
      const raw=match[0],display=raw.startsWith('$$')||raw.startsWith('\\['),cut=raw.startsWith('$')&&!display?1:2;
      const alone=display&&matches.length===1&&source.trim()===raw&&text.parentElement.tagName==='P'&&text.parentElement.childNodes.length===1;
      const node=document.createElement(alone?'div':'span');node.dataset.type=alone?'block-math':'inline-math';node.dataset.latex=raw.slice(cut,-cut).trim();
      fragment.append(node);offset=match.index+raw.length;
      if(alone){text.parentElement.replaceWith(node);break;}
    }
    if(root.contains(text)){fragment.append(document.createTextNode(source.slice(offset)));text.replaceWith(fragment);}
  }
  return root.innerHTML;
}
