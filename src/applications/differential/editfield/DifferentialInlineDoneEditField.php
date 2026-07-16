<?php

final class DifferentialInlineDoneEditField
  extends PhabricatorEditField {

  protected function newEditType() {
    return new DifferentialInlineDoneEditType();
  }

  protected function newConduitParameterType() {
    return new ConduitWildParameterType();
  }

}
