<!doctype html>

<html lang="en">
<head>
  <meta charset="utf-8">

  <title>Assets Overview</title>
  <style>
  * {
      transition: all 0.2s ease;
      font-family: arial, sans-serif;
      color: rgb(79, 88, 97);
      font-size: 13px;
  }
  p {margin: 0; padding: 0;}
  .break {
      clear: both;
      border-top: 1px dotted #ddd;
  }
  h1, h2, h3, h4 {
      display: block;
      padding-top: 15px;
      padding-bottom: 0;
      margin-bottom: 0;
  }
  h1 {
      font-size: 20px;
  }
  h2 {
      font-size: 18px;

  }
  h3 {
      font-size: 16px;

  }
  h4 {
      font-size: 14px;
  }

  a:link, a:visited {
      text-decoration: none;
      color: #304e80;
  }
  a:hover {
      text-decoration: underline;
  }

  .padding {padding: 10px;}
  .results {
      width: 70%;
  }
  li.current {
      font-weight: bold;
  }
  .toc {
      position: fixed;
      width: 30%;
      top: 0;
      bottom: 0;
      right: 0;
      left: 70%;
      background-color: #b0bec7;
      overflow-y: auto;
      font-family: sans-serif;
      border-left: 1px solid #304e80;
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
      background-color: #304e80;
      color: #fff;
      text-decoration: none;
  }


  .one-image {
      position: relative;
      display: block;
      float: left;
      height: 250px;
      width: auto;
      border: 1px solid #ddd;
      margin: 10px;
      background-color: #eee;
      min-width: 250px;
      overflow: hidden;
      border-radius: 5px;
  }
  .one-image img {
      display: block;
      height: 250px;
      margin-left: auto;
      margin-right: auto;
  }

  .one-image-info {
      background-color: transparent;
      height: 0;
      text-align: left;
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      font-family: sans-serif;
  }

  .one-image:hover .one-image-info {
      background-color: rgba(0,0,0, 0.7);
      height: 200px;
      padding: 7px;
      color: #fff;
  }

  .one-image-info u {
      color: #fff;
      font-weight: bold;
      display: block;
      padding-bottom: 7px;
      text-decoration: none;
  }
  .one-image-info strong {
      color: #ddd;
  }
  .one-image-info a {
       color: #eee!important;
   }
   .one-image-info a:hover {
       text-decoration: none;
       color: #304e80;
   }
  .edit-icon {
      z-index: 99;
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
      color: #fff;
      text-decoration: none!important;
  }
  .edit-icon.error {
      background-color: pink;
  }

  </style>
</head>

<body>
    <div class="toc">
        <div class="padding">
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
            <p>&laquo; <a href="/admin/assets/">back to CMS</a></p>

            <h1>Totals</h1>
            <p>$TotalFileCount files, totalling $TotalFileSize in storage</p>

            <h1>View By ...</h1>
            <ul>
                <% loop $ActionMenu %>
                <li class="$LinkingMode"><a href="$Link">$Title</a></li>
                <% end_loop %>
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
