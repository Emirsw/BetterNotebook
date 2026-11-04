// Occupancy is computed from the original PDF pixels, never from the annotation layer.
export function buildInkGrid(data,width,height,columns=60){
  const rows=Math.max(1,Math.ceil(columns*height/width)),cells=new Uint8Array(columns*rows);
  for(let y=0;y<height;y++)for(let x=0;x<width;x++){const p=(y*width+x)*4;
    if(data[p+3]>128&&(data[p]*.2126+data[p+1]*.7152+data[p+2]*.0722)<245){
      const cx=Math.min(columns-1,Math.floor(x*columns/width)),cy=Math.min(rows-1,Math.floor(y*rows/height));
      // A one-cell safety border also protects thin strokes and text edges.
      for(let dy=-1;dy<=1;dy++)for(let dx=-1;dx<=1;dx++)if(cx+dx>=0&&cx+dx<columns&&cy+dy>=0&&cy+dy<rows)cells[(cy+dy)*columns+cx+dx]=1;
    }
  }return {columns,rows,cells};
}
export function rectFits(grid,box,reserved=[]){
  if(!box||box.length!==4||box.some(n=>!Number.isFinite(n))||box[0]<0||box[1]<0||box[2]<=0||box[3]<=0||box[0]+box[2]>1000||box[1]+box[3]>1000)return false;
  if(reserved.some(r=>box[0]<r[0]+r[2]+8&&box[0]+box[2]+8>r[0]&&box[1]<r[1]+r[3]+8&&box[1]+box[3]+8>r[1]))return false;
  const x0=Math.floor(box[0]*grid.columns/1000),x1=Math.ceil((box[0]+box[2])*grid.columns/1000),y0=Math.floor(box[1]*grid.rows/1000),y1=Math.ceil((box[1]+box[3])*grid.rows/1000);
  for(let y=y0;y<y1;y++)for(let x=x0;x<x1;x++)if(grid.cells[y*grid.columns+x])return false;return true;
}
export function findWhiteSpace(grid,width,height,reserved=[]){
  if(width>960||height>960)return null;
  // Prefer the right-hand white margin; then try lower/left blank spaces.
  for(const x of [1000-width-24,1000-width-70,24,80,Math.max(24,(1000-width)/2)]){
    for(let y=110;y+height<980;y+=1000/grid.rows){const box=[x,y,width,height];if(rectFits(grid,box,reserved))return box;}
  }return null;
}

// Expand only the source screenshot, keeping the focused highlight unchanged.
export function expandSourceRegion(grid,region){
 const {columns,rows,cells}=grid;
 let l=Math.max(0,Math.floor(region[0]*columns/1000)-2),r=Math.min(columns-1,Math.ceil((region[0]+region[2])*columns/1000)+2);
 let top=Math.max(0,Math.floor(region[1]*rows/1000)-2),bottom=Math.min(rows-1,Math.ceil((region[1]+region[3])*rows/1000)+2);
 const ink=(x0,y0,x1,y1)=>{for(let y=Math.max(0,y0);y<=Math.min(rows-1,y1);y++)for(let x=Math.max(0,x0);x<=Math.min(columns-1,x1);x++)if(cells[y*columns+x])return true;return false;};
 for(let pass=0;pass<columns+rows;pass++){
  const left=l>0&&ink(l-1,top,l+1,bottom),right=r<columns-1&&ink(r-1,top,r+1,bottom);
  const up=top>0&&ink(l,top-1,r,top+1),down=bottom<rows-1&&ink(l,bottom-1,r,bottom+1);
  if(!left&&!right&&!up&&!down)break;
  if(left)l--;if(right)r++;if(up)top--;if(down)bottom++;
 }
 l=Math.max(0,l-2);r=Math.min(columns-1,r+2);top=Math.max(0,top-2);bottom=Math.min(rows-1,bottom+2);
 return [l*1000/columns,top*1000/rows,(r-l+1)*1000/columns,(bottom-top+1)*1000/rows];
}
