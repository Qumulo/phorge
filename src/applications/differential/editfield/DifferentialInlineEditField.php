<?php

final class DifferentialInlineEditField
  extends PhabricatorEditField {

  protected function newEditType() {
    return new DifferentialInlineEditType();
  }

  protected function newConduitParameterType() {
    return new ConduitWildParameterType();
  }

}
