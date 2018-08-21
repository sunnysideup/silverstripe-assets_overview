




<!doctype html>

<html lang="en">
<head>
  <meta charset="utf-8">

  <title>Assets Overview</title>
  <style>
  * {
      transition: all 0.2s ease;
  }
  p {margin: 0; padding: 0;}
  .break {
      clear: both;
      border-top: 1px solid #ccc;
  }
  h1, h2, h3, h4 {
      display: block;
      padding-top: 20px;
      padding-bottom: 0;
      margin-bottom: 0;
  }
  h1 {
      border-top: 1px solid #000;
  }
  a:link, a:visited {
      text-decoration: none;
      color: navy;
  }
  a:hover {
      text-decoration: underline;
  }
  .padding {padding: 10px;}
  .results {
      width: 70%;
  }
  .toc {
      position: fixed;
      width: 30%;
      top: 0;
      bottom: 0;
      right: 0;
      left: 70%;
      background-color: pink;
      overflow-y: auto;
      font-family: sans-serif;
      font-size: 13px;
      border-left: 1px solid red;
  }
  .toc ul {
      padding: 0px;
      margin: 0;
  }
  .toc li {
      list-style: none;
      border-bottom: 1px solid #eee;
      padding: 0px;
      margin: 0;
  }
  .toc li a {
      padding: 4px;
      display: block;
  }
  .toc li a:hover {
      background-color: navy;
      color: #fff;
      text-decoration: none;
  }


  .one-image {
      position: relative;
      display: block;
      float: left;
      height: 150px;
      width: auto;
      border: 1px solid #ddd;
      margin: 10px;
      background-color: #eee;
      min-width: 50px;
      overflow: hidden;
      border-radius: 5px;
  }
  .one-image img {
      display: block;
      height: 150px;
      margin-left: auto;
      margin-right: auto;
  }

  .one-image-info {
      background-color: transparent;
      height: 0;
      font-size: 13px;
      text-align: left;
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      font-family: sans-serif;
  }
  .one-image:hover .one-image-info {
      background-color: rgba(0,0,0, 0.5);
      height: 100px;
      padding: 7px;
      color: #fff;
  }
  .one-image-info a {
       color: #ccc;
       font-weight: 600;

   }
   .one-image-info a:hover {
       text-decoration: none;
       color: navy;
   }
  .edit-icon {
      position: absolute;
      top: 10px;
      right: 10px;
      background-color: rgba(0,0,0,0.3);
      display: block;
      width: 30px;
      height: 30px;
      color: #fff;
      border-radius: 50%;
      text-align: center;
      vertical-align: bottom;
      text-decoration: none;
      line-height: 30px;
      font-size: 20px;
  }
  .edit-icon:hover {
      background-color: green;
      text-decoration: none!important;
  }
  .edit-icon.error {
      background-color: red;
  }

  </style>
</head>

<body>
    <div class="toc">
        <div class="padding">
            <h4>TOC</h4>
            <% if ImagesSorted.count %>
                <ul>
                    <li><a href="#top">View By Options ...</a><br /></li>
                <% loop ImagesSorted %>
                    <li><a href="#section-$Number">$SubTitle ($Items.Count)</a></li>
                <% end_loop %>
                </ul>
            <% end_if %>
        </div>
    </div>

    <div id="top" class="results">
        <div class="padding">
            <h1>View By ...</h1>
            <ul>
                <li><a href="$Link(byfolder)">Folder</a></li>
                <li><a href="$Link(byfilename)">Name</a></li>
                <li><a href="$Link(byfilesize)">File Size</a></li>
                <li><a href="$Link(bydimensions)">Dimensions</a></li>
                <li><a href="$Link(byratio)">Ratio</a></li>
                <li><a href="$Link(bylastedited)">Last Edited</a></li>
                <li><a href="$Link(bydatabasestatus)">Database Status</a></li>
                <li><a href="$Link(bysimilarity)">Similarity (not so reliable)</a></li>
            </ul>


            <div>
            <% if ImagesSorted.count %>
                <h1>$Title</h1>
                <% loop ImagesSorted %>
                    <div id="section-$Number" class="break">
                        <h3>$SubTitle</h3>
                        <% loop Items %>
                        <% include OneImage %>
                        <% end_loop %>
                    </div>
                <% end_loop %>
            <% end_if %>
            </div>
        </div>
    </div>
</body>
</html>
